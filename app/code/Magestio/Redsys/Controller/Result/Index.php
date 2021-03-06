<?php

namespace Magestio\Redsys\Controller\Result;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magestio\Redsys\Helper\Helper;
use Magestio\Redsys\Logger\Logger;
use Magestio\Redsys\Model\RedsysApi;
use Magestio\Redsys\Model\ConfigInterface;

/**
 * Class Index
 * @package Magestio\Redsys\Controller\Result
 */
class Index extends Action
{

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var ResultFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var OrderInterface;
     */
    protected $order = null;

    /**
     * @var RedsysApi
     */
    protected $api = null;

    /**
     * Index constructor.
     * @param Context $context
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param ResultFactory $resultRedirectFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param Helper $helper
     * @param Logger $logger
     */
	public function __construct(
		Context $context,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        ResultFactory $resultRedirectFactory,
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
        Helper $helper,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface|null
     */
    public function execute()
    {
        if ($this->getRequest()->isPost()) {
            $this->process();
        } else {
            $resultRedirect = $this->resultRedirectFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('');
            return $resultRedirect;
        }
    }
    
    protected function process()
    {
        try {

            $this->validate();
            $api = $this->getApi();
            $responseCode = intval($api->getParameter('Ds_Response'));

            if ($responseCode <= 99) {
                $this->processOrder();
                $this->processInvoice();
            } else {
                $errorMessage = $this->helper->messageResponse($responseCode)." ".__("(response:%1)",$responseCode);
                $this->helper->cancelOrder($this->getOrder(), $errorMessage);
            }

        } catch (\Exception $e) {
            $this->logger->critical($e);
        }

    }

    /**
     *  Puts order in Processing State and Status
     */
    private function processOrder()
    {
        $order = $this->getOrder();
        $payment = $order->getPayment();

        $state = Order::STATE_PROCESSING;
        $status = $this->helper->getOrderStatusByState($order, $state);

        $order->setState($state);
        $order->setStatus($status);

        $transaction = $payment->addTransaction(PaymentTransaction::TYPE_CAPTURE);
        $api = $this->getApi();
        $responseCode = intval($api->getParameter('Ds_Response'));
        $authorisationCode = $api->getParameter('Ds_AuthorisationCode');
        $message = $payment->prependMessage(__('TPV payment accepted. (response: %1, authorization: %1)', $responseCode, $authorisationCode));
        $payment->addTransactionCommentsToOrder($transaction, $message);

        $this->orderRepository->save($order);

    }

    /**
     * Creates the invoice and sends the email
     *
     * @throws LocalizedException
     */
    private function processInvoice()
    {
        if ($this->scopeConfig->getValue(ConfigInterface::XML_PATH_AUTOINVOICE, ScopeInterface::SCOPE_STORE)) {
            $order = $this->getOrder();

            if (!$order->canInvoice()) {
                throw new LocalizedException(__('The order does not allow an invoice to be created.'));
            }

            $invoice = $this->invoiceService->prepareInvoice($order);

            if (!$invoice) {
                throw new LocalizedException(__('We can\'t save the invoice right now.'));
            }

            if (!$invoice->getTotalQty()) {
                throw new LocalizedException(__('You can\'t create an invoice without products.'));
            }

            $invoice->setRequestedCaptureCase(Invoice::NOT_CAPTURE);

            $invoice->register();

            $invoice->getOrder()->setCustomerNoteNotify(true);
            $invoice->getOrder()->setIsInProcess(true);

            $transactionSave = $this->_objectManager->create(Transaction::class)
                ->addObject($invoice)
                ->addObject($invoice->getOrder());

            $transactionSave->save();

            // send invoice email
            if ($this->scopeConfig->getValue(ConfigInterface::XML_PATH_SENDINVOICE, ScopeInterface::SCOPE_STORE)) {
                try {
                    $this->invoiceSender->send($invoice);
                } catch (\Exception $e) {
                    $this->logger->critical($e);
                }
            }
        }
    }

    /**
     * @return OrderInterface
     * @throws LocalizedException
     */
    private function getOrder()
    {
        if (is_null($this->order)) {
            $api = $this->getApi();
            $orderId = $api->getParameter('Ds_Order');
            $this->order = $this->helper->getOrderByIncrementId($orderId);
        }
        return $this->order;
    }

    /**
     * @return RedsysApi
     */
    private function getApi()
    {
        if (is_null($this->api)) {
            $data = $this->getRequest()->getParam("Ds_MerchantParameters");
            $this->api = new RedsysAPI();
            $this->api->decodeMerchantParameters($data);
        }
        return $this->api;
    }

    /**
     * @throws LocalizedException
     */
    private function validate()
    {
        $data = $this->getRequest()->getParam("Ds_MerchantParameters");
        $signatureResponse = $this->getRequest()->getParam("Ds_Signature");

        if (is_null($data) or is_null($signatureResponse)) {
            throw new LocalizedException(__('Incorrect response from Redsys.'));
        }

        $api = $this->getApi();
        $sha256key = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_KEY256, ScopeInterface::SCOPE_STORE);
        $signature = $api->createMerchantSignatureNotif($sha256key, $data);

        $orderId = $api->getParameter('Ds_Order');
        $merchantCode = $api->getParameter('Ds_MerchantCode');
        $terminal = $api->getParameter('Ds_Terminal');
        $transaction = $api->getParameter('Ds_TransactionType');

        $merchantCodeMagento = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_COMMERCE_NUM, ScopeInterface::SCOPE_STORE);
        $terminalMagento = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_TERMINAL, ScopeInterface::SCOPE_STORE);
        $transactionMagento = $this->scopeConfig->getValue(ConfigInterface::XML_PATH_TRANSACTION_TYPE, ScopeInterface::SCOPE_STORE);

        if ($signature !== $signatureResponse
            or !isset($orderId)
            or $transaction != $transactionMagento
            or $merchantCode != $merchantCodeMagento
            or intval(strval($terminalMagento)) != intval(strval($terminal))
        ) {
            throw new LocalizedException(__('Errors in POST data'));
        }

        $amount = $api->getParameter('Ds_Amount');
        $orderId = $api->getParameter('Ds_Order');
        $order = $this->getOrder($orderId);

        $transaction_amount = number_format($order->getBaseGrandTotal(), 2, '', '');
        $amountOrder = (float)$transaction_amount;
        if ($amountOrder != $amount) {
            throw new LocalizedException(__("Amount is diferent"));
        }

    }

}