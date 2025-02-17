<?php

namespace UnzerPayment\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use UnzerPayment\Contracts\TransactionRepositoryContract;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\ExternalOrderService;
use UnzerPayment\Traits\LoggingTrait;

class SystemController extends Controller
{
    use LoggingTrait;
    private Response $response;
    private Request $request;

    public function __construct(Response $response, Request $request)
    {
        parent::__construct();
        $this->response = $response;
        $this->request = $request;
    }

    public function externalOrderMatching(): string
    {
        $this->log(__CLASS__, __METHOD__, 'start');
        pluginApp(ExternalOrderService::class)->process();
        return 'done';
    }

    public function test(): string
    {
        $this->log(__CLASS__, __METHOD__, 'start');
        $action = $this->request->get('action');
        $parameters = $this->request->all();
        $apiService = pluginApp(ApiService::class);
        $response = $apiService->call($action, $parameters);
        return json_encode($response, JSON_PRETTY_PRINT);

    }

    public function getTable(TransactionRepositoryContract $transactionRepository)
    {
        $this->log(__CLASS__, __METHOD__, 'start');
        if(md5((string)$this->request->get('auth')) !== '5e98292bc2acc564884a5d8ff7185043'){
            return 'no auth';
        }

        $transactions = $transactionRepository->getTransactions([['id', '>', 0]]);
        $html = <<<HTML
            <style>
                table {
                    border-collapse: collapse;
                }
                table td {
                    border: 1px solid #ccc;
                    padding: 5px;
                }
                tr:nth-child(even) {
                    background-color: #eee;
                }
            </style>
HTML;

        $html .= '<table style="font-family: monospace; font-size:10px;">';
        foreach ($transactions as $transaction) {
            $html .= '<tr>';
            foreach ($transaction as $k => $v) {
                $html .= '<td data-field="'.$k.'">' . $v . '</td>';
            }
            $html .= '</tr>';
        }

        return $html;
    }

}