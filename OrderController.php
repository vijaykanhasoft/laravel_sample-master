/*
* This is OrderController Controller
* Here logic of relate order
* This is php laravel freamwork
*/
<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use App\Jobs\SyncToS3Job;
use App\Services\CommonServices;
use App\Services\ICustomerService;
use App\Services\IOrderService;
use App\Services\IProductService;
use Auth;
use Carbon\Carbon;
use DB;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Response;
use View;
use ZipArchive;

/**
 * Include order insert,edit,delete,search and listing.
 */
class OrderController extends Controller
{
    /**
     * Instantiate a new OrderController instance.
     *
     * @return void
     */
    public function __construct(IOrderService $orderService, IProductService $productService, ICustomerService $customerService)
    {
        $this->orderService = $orderService;
        $this->productService = $productService;
        $this->customerService = $customerService;
    }

    /**
     * order Controller Index.
     * @Request get varibles for search
     * Get all order with page limit
     * @return View
     */
    public function Index(Request $request)
    {
        $input = $request->Input();
        $input['paginate'] = 1;
        $input['columns'] = [
            'orders.id',
            'orders.invoice_number',
            'customers.company_name',
            'consignees.company_name AS delivery_company_name',
            'orders.payment_method',
            'orders.grand_total',
            'orders.payment_status',
            'orders.purchase_order',
            'orders.percent_packed',
            'orders.created_on',
            'users.username AS agent',
            'customers.courtesy_title',
            'customers.first_name',
            'customers.last_name',
            'customers.address_1',
            'customers.address_2',
            'customers.town',
            'customers.county',
            'customers.country_id',
            'customers.postcode',
            'customers.blocked',
            'consignees.first_name AS delivery_first_name',
            'consignees.last_name AS delivery_last_name',
            'consignees.address_1 AS delivery_address_1',
            'consignees.address_2 AS delivery_address_2',
            'consignees.town AS delivery_town',
            'consignees.county AS delivery_county',
            'consignees.country_id AS delivery_country_id',
            'consignees.postcode AS delivery_postcode',
            'orders.net_total',
            'orders.shipping_total',
            'orders.tax_total',
            'orders.cheque_number',
            'customers.email',
            'orders.discount_total',
            'orders.paid_on',
            'orders.cancelled_on',
            'orders.due_on',
            'orders.invoiced_on',
            'orders.despatched_on',
            'orders.baddebt_on',
            'orders.debt_compensation',
            'orders.agent_id',
            'orders.cpdDiscountCode',
            'orders.debt_interest', 'order_dispatch.dispatched_status', 'orders.unpublishedPayment', 'orders.refunded', 'orders.refunded_date',
        ];
        $input['search_filters'] = $data['search_filters'] = $this->searchFields();
        $orders = $this->orderService->getAllOrders($input);
        $total = $this->orderService->getTotal($input);

        if (! empty($orders)) {
            $orders->appends($request->Input()); // append get variables for pagination
        }
        $data['orders'] = $orders;
        $data['totals'] = $total;
        $data['ordersideView'] = 1;
        $data['pageLimit'] = (isset($input['ps']) && $input['ps']) ? $input['ps'] : 30;
        $data['queryStringFromUrl'] = $request->getQueryString();

        return View::make('orders.listing', $data);
    }

    /**
     * create order view page.
     * @return View
     */
    public function create()
    {
        $data['ordersideView'] = 1;

        return View::make('orders/create', $data);
    }

    /**
     * Insert to order.
     * @Request post varibles for order insert
     * @validate return error array
     * @return primary id
     */
    public function store(Request $request)
    {
        $errorMessages = $this->orderService->validate($request->Input());
        if (! empty($errorMessages)) {
            return Redirect::to('orders/create')->with('error', 1)->with('messages', $errorMessages)->withInput();
        }
        $input = $request->Input();
        if ($request->Input('autoCustomerid') && isset($input['autoCustomerid']) && $input['autoCustomerid'] != '') {
            $input['customer_id'] = $input['autoCustomerid'];
            //Update existing customer
            $this->customerService->customerBasicinfoUpdate($input['customer_update'], $input['customer_id']);
        } elseif ($request->Input('customer') && $input['autoCustomerid'] == '') {
            $input['customer_id'] = $this->customerService->customerCreate($input['customer']);
        }
        if ($input['deliver_to_customer'] == 'yes') {
            $input['consignee_id'] = 'NULL';
        } elseif (isset($input['consignee'])) {
            $input['consignee_id'] = $this->orderService->consigneeCreate($input['consignee']);
        }
        $id = $this->orderService->OrderCreate($input);

        return Redirect::to('orders/'.$id.'/edit');
    }

    /**
     * Edit page for order.
     * @id order id
     * @return If order not found
     * @return view
     */
    public function edit($id)
    {
        $order = $this->orderService->getOrderById($id);
        $promoCode = $this->orderService->getDiscountCode($id);

        if (empty($order)) {
            return Redirect::to('orders');
        }

        $data['service_reminders'] = $this->orderService->getReminders($order->customer_id, 'Service Reminder');
        $data['promoCode'] = $promoCode;
        $data['order'] = $order;
        $data['comments'] = $order->comments;
        $data['ordersideView'] = 1;

        return View::make('orders.edit', $data);
    }

    /**
     * Update order.
     * @Request post varibles for customer order
     * @id order id
     * @validate return error array
     * @return to order list
     */
    public function update(Request $request, $id)
    {
        $order = $this->orderService->getOrderById($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $errorMessages = $this->orderService->validate($request->Input());
        if (! empty($errorMessages)) {
            return Redirect::to('orders/'.$id.'/edit')->with('error', 1)->with('messages', $errorMessages)->withInput();
        }
        $input = $request->Input();
        if ( $request->Input( 'customer' ) && $input[ 'autoCustomerid' ] == '' ) {
            $input[ 'customer_id' ] = $this->customerService->customerCreate( $input[ 'customer' ] );
        }

        if ($input['deliver_to_customer'] == 'yes') {
            $input['consignee_id'] = 'NULL';
        } elseif (isset($input['consignee_id']) && $input['consignee_id']) {
            $this->orderService->consigneeUpdate($input['consignee_id'], $input['consignee']);
        } elseif ($input['consignee']) {
            $input['consignee_id'] = $this->orderService->consigneeCreate($input['consignee']);
        }

        $this->orderService->OrderUpdate( $input, $order->id, $order );
        if ( $request->Input( 'autoCustomerid' ) && isset( $input[ 'autoCustomerid' ] ) && $input[ 'autoCustomerid' ] != '' ) {
            $input[ 'customer_id' ] = $input[ 'autoCustomerid' ];
            if( $request->Input( 'customer_update' ) ) {
                #Changes made by Kevin for KEV-144
                #$this->customerService->customerBasicinfoUpdate($input['customer_update'], $input['customer_id']);
                $this->orderService->updateOrderCustomerHistoryNew($id,$input['customer_update']);
            }
        }
        return Redirect::to( 'orders/' . $id . '/edit' );
    }

    /**
     * @data['id'] order id
     * @return error order id not set
     * @return error order not found
     * @set payment status = Cancelled
     * @return success
     */
    protected function cancelOrder(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            if (! isset($data['id'])) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Id missing';

                return response()->json($arr);
                exit();
            }
            $order = $this->orderService->getOrderById($data['id']);
            if (! $order) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Order not exist';

                return response()->json($arr);
                exit();
            }
            $this->orderService->CancelOrder($data['id']);
        }
        $arr['success'] = 1;

        return response()->json($arr);
        exit();
    }

    /**
     * @data['id'] order id
     * @return error order id not set
     * @return error order not found
     * @set payment status = Bad Debt
     * @return success
     */
    protected function debtOrder(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            if (! isset($data['id'])) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Id missing';

                return response()->json($arr);
                exit();
            }
            $order = $this->orderService->getOrderById($data['id']);
            if (! $order) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Order not exist';

                return response()->json($arr);
                exit();
            }
            $this->orderService->badDebtOrder($data['id'], $order->payment_status);
        }
        $arr['success'] = 1;

        return response()->json($arr);
        exit();
    }

    /**
     * @data['id'] order id
     * @return error order id not set
     * @return error order not found
     * @set payment status = old status
     * @return success
     */
    protected function removeDebtOrder(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            if (! isset($data['id'])) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Id missing';

                return response()->json($arr);
                exit();
            }
            $order = $this->orderService->getOrderById($data['id']);
            if (! $order) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Order not exist';

                return response()->json($arr);
                exit();
            }
            $this->orderService->removeBadDebtOrder($data['id']);
        }
        $arr['success'] = 1;

        return response()->json($arr);
        exit();
    }

    /** export current page.
     * @request get variables
     * @return csv file
     * @TrimStr using to avoid new line
     */
    public function export(Request $request)
    {
        $input = $request->Input();
        $input['paginate'] = 1;
        $input['search_filters'] = $this->searchFields();
        $input['columns'] = [
            'orders.id',
            'orders.agent_id',
            'orders.customer_id',
            'orders.consignee_id',
            'orders.invoice_number',
            'orders.nominal_code',
            'orders.purchase_order',
            'orders.net_total',
            'orders.discount_total',
            'orders.shipping_total',
            'orders.tax_total',
            'orders.grand_total',
            'orders.debt_compensation',
            'orders.debt_interest',
            'orders.payment_method',
            'orders.payment_status',
            'orders.cheque_number',
            'orders.invoice_note',
            'orders.created_on',
            'orders.created_by',
            'orders.updated_on',
            'orders.updated_by',
            'orders.invoiced_on',
            'orders.due_on',
            'orders.paid_on',
            'orders.despatched_on',
            'orders.percent_packed',
            'orders.despatched_by',
            'orders.cancelled_on',
            'orders.cancelled_by',
            'orders.debt_letter_1_sent_on',
            'orders.debt_letter_2_sent_on',
            'orders.test_mode',
            'orders.osn_order_ref',
            'orders.discount_code_id',
            'wp_transactions.transId as worldpay_trans_id',
            'customers.company_name',
            'customers.account_number',
            'customers.courtesy_title',
            'customers.first_name',
            'customers.last_name',
            'customers.address_1',
            'customers.address_2',
            'customers.town',
            'customers.county',
            'customers.country_id',
            'customers.postcode',
            'customers.email',
            'customers.type as customer_type',
            'customers.mail_opt_in as customer_mail_opt_in',
            'consignees.company_name AS delivery_company_name',
            'consignees.first_name AS delivery_first_name',
            'consignees.last_name AS delivery_last_name',
            'consignees.address_1 AS delivery_address_1',
            'consignees.address_2 AS delivery_address_2',
            'consignees.town AS delivery_town',
            'consignees.county AS delivery_county',
            'consignees.country_id AS delivery_country_id',
            'consignees.postcode AS delivery_postcode', 'order_dispatch.dispatched_status', 'orders.refunded', 'orders.refunded_date',
            // 'users.username AS agent'
        ];
        $orders = $this->orderService->getAllOrders($input);

        if (! empty($orders)) {
            foreach ($orders as $row) {
                $output[] = [
                    'invoice_number' => ($row->invoice_number) ? TrimStr($row->invoice_number) : '--',
                    'title' => ($row->courtesy_title) ? TrimStr($row->courtesy_title) : '--',
                    'first_name' => ($row->first_name) ? TrimStr($row->first_name) : '--',
                    'last_name' => ($row->last_name) ? TrimStr($row->last_name) : '--',
                    'company_name' => ($row->company_name) ? TrimStr($row->company_name) : '--',
                    'address_1' => ($row->address_1) ? TrimStr($row->address_1) : '--',
                    'address_2' => ($row->address_2) ? TrimStr($row->address_2) : '--',
                    'town' => ($row->town) ? TrimStr($row->town) : '--',
                    'county' => ($row->county) ? TrimStr($row->county) : '--',
                    'postcode' => ($row->postcode) ? TrimStr($row->postcode) : '--',
                    'Email' => ($row->email) ? TrimStr($row->email) : '--',
                    'Phone' => ($row->phone) ? TrimStr($row->phone) : '--',
                    'mobile' => ($row->mobile_phone) ? TrimStr($row->mobile_phone) : '--',
                    'Fax' => ($row->fax) ? TrimStr($row->fax) : '--',
                    'net total' => TrimStr($row->net_total),
                    'Postage' => TrimStr($row->shipping_total),
                    'VAT' => TrimStr($row->tax_total),
                    'Grant Total' => TrimStr($row->grand_total),
                    'Credit slip number' => TrimStr($row->cheque_number),
                    'Created' => date('d/m/Y', strtotime($row->created_on)),
                    'Payment method' => TrimStr($row->payment_method),
                    'Invoiced on' => ($row->invoiced_on != null && $row->invoiced_on != '0000-00-00 00:00:00') ? date('d/m/Y', strtotime($row->invoiced_on)) : '--',
                    'Payment status' => TrimStr($row->payment_status),
                    'Payment date' => ($row->paid_on != null && $row->paid_on != '0000-00-00 00:00:00') ? date('d/m/Y', strtotime($row->paid_on)) : '--',
                    'Percent packed' => TrimStr($row->percent_packed),
                    'Worldpay transaction ID' => ($row->worldpay_trans_id) ? TrimStr($row->worldpay_trans_id) : '--',
                    'Additionnal notes' => TrimStr($row->invoice_note),
                    'Refunded date' => ($row->refunded == 'yes' && $row->refunded_date) ? date('d/m/Y', strtotime($row->refunded_date)) : '--',
                ]; // append each row
            }
        } else {
            $output[] = [
                'No records found',
            ];
        }

        return $this->simpleExport($output, 'orders', 'csv', 'ordersheet');
    }

    /** export all orders with search filters.
     * @request get variables
     * @return Zip file
     * @TrimStr using to avoid new line
     */
    public function allExport(Request $request)
    {
        $input = $request->Input();
        $data['url'] = $data['dlpath'] = $data['success'] = $data['emptyMsg'] = '';
        if (isset($input['f'])) {
            foreach ($input['f'] as $key => $row) {
                $data['url'] .= 'f['.$key.']='.$row.'&'; // contain get variables
            }
        }
        if (isset($input['o']) and isset($input['d'])) {
            $data['url'] .= 'o='.$input['o'].'&d='.$input['d'].'&'; // contain get variables
        }
        if (isset($input['page']) && $input['page'] != '') {
            $input['page'] = $page = isset($input['page']) ? $input['page'] : 1;
            $input['exportPaginate'] = 1;
            $input['search_filters'] = $this->searchFields();
            $input['columns'] = [
                'orders.id',
                'orders.agent_id',
                'orders.customer_id',
                'orders.consignee_id',
                'orders.invoice_number',
                'orders.nominal_code',
                'orders.purchase_order',
                'orders.net_total',
                'orders.discount_total',
                'orders.shipping_total',
                'orders.tax_total',
                'orders.grand_total',
                'orders.debt_compensation',
                'orders.debt_interest',
                'orders.payment_method',
                'orders.payment_status',
                'orders.cheque_number',
                'orders.invoice_note',
                'orders.created_on',
                'orders.created_by',
                'orders.updated_on',
                'orders.updated_by',
                'orders.invoiced_on',
                'orders.due_on',
                'orders.paid_on',
                'orders.despatched_on',
                'orders.percent_packed',
                'orders.despatched_by',
                'orders.cancelled_on',
                'orders.cancelled_by',
                'orders.debt_letter_1_sent_on',
                'orders.debt_letter_2_sent_on',
                'orders.test_mode',
                'orders.osn_order_ref',
                'orders.discount_code_id',
                'wp_transactions.transId as worldpay_trans_id',
                'customers.company_name',
                'customers.account_number',
                'customers.courtesy_title',
                'customers.first_name',
                'customers.last_name',
                'customers.address_1',
                'customers.address_2',
                'customers.town',
                'customers.county',
                'customers.country_id',
                'customers.postcode',
                'customers.email',
                'customers.type as customer_type',
                'customers.mail_opt_in as customer_mail_opt_in',
                'consignees.company_name AS delivery_company_name',
                'consignees.first_name AS delivery_first_name',
                'consignees.last_name AS delivery_last_name',
                'consignees.address_1 AS delivery_address_1',
                'consignees.address_2 AS delivery_address_2',
                'consignees.town AS delivery_town',
                'consignees.county AS delivery_county',
                'consignees.country_id AS delivery_country_id',
                'consignees.postcode AS delivery_postcode', 'order_dispatch.dispatched_status', 'orders.refunded', 'orders.refunded_date',
                // 'users.username AS agent'
            ];
            $result = $this->orderService->getAllOrders($input);
            $count = $result->lastPage(); // getting order last page no
            if ($count == 0) {
                $data['emptyMsg'] = 1;
                $data['page'] = '';

                return View::make('export', $data);
            }
            $csv = [];

            if ($page <= $count) { //checking last page no matches with page
                if (! empty($result)) {
                    foreach ($result as $row) {
                        $csv[] = [
                            'invoice_number' => ($row->invoice_number) ? TrimStr($row->invoice_number) : '--',
                            'title' => ($row->courtesy_title) ? TrimStr($row->courtesy_title) : '--',
                            'first_name' => ($row->first_name) ? TrimStr($row->first_name) : '--',
                            'last_name' => ($row->last_name) ? TrimStr($row->last_name) : '--',
                            'company_name' => ($row->company_name) ? TrimStr($row->company_name) : '--',
                            'address_1' => ($row->address_1) ? TrimStr($row->address_1) : '--',
                            'address_2' => ($row->address_2) ? TrimStr($row->address_2) : '--',
                            'town' => ($row->town) ? TrimStr($row->town) : '--',
                            'county' => ($row->county) ? TrimStr($row->county) : '--',
                            'postcode' => ($row->postcode) ? TrimStr($row->postcode) : '--',
                            'Email' => ($row->email) ? TrimStr($row->email) : '--',
                            'Phone' => ($row->phone) ? TrimStr($row->phone) : '--',
                            'mobile' => ($row->mobile_phone) ? TrimStr($row->mobile_phone) : '--',
                            'Fax' => ($row->fax) ? TrimStr($row->fax) : '--',
                            'net total' => TrimStr($row->net_total),
                            'Postage' => TrimStr($row->shipping_total),
                            'VAT' => TrimStr($row->tax_total),
                            'Grant Total' => TrimStr($row->grand_total),
                            'Credit slip number' => TrimStr($row->cheque_number),
                            'Created' => date('d/m/Y', strtotime($row->created_on)),
                            'Payment method' => TrimStr($row->payment_method),
                            'Invoiced on' => ($row->invoiced_on != null && $row->invoiced_on != '0000-00-00 00:00:00') ? date('d/m/Y', strtotime($row->invoiced_on)) : '--',
                            'Payment status' => TrimStr($row->payment_status),
                            'Payment date' => date('d/m/Y', strtotime($row->paid_on)),
                            'Percent packed' => TrimStr($row->percent_packed),
                            'Worldpay transaction ID' => ($row->worldpay_trans_id) ? TrimStr($row->worldpay_trans_id) : '--',
                            'Additionnal notes' => TrimStr($row->invoice_note),
                            'Refunded date' => ($row->refunded == 'yes' && $row->refunded_date) ? date('d/m/Y', strtotime($row->refunded_date)) : '--',
                        ]; // append each row
                    }
                } else {
                    $csv[] = [
                        'No records found',
                    ];
                }
                $this->multipleExport($csv, 'csv', $page, 'orders');
            } else {
                $data['dlpath'] = $this->createZipFromExports('orders');
                $data['success'] = 1;
            }
            $data['page'] = $page + 1; // next page
        }

        return View::make('export', $data);
    }

    /** page for invoice and proforma.
     * @param  order id
     * @param  type - invoice or proforma
     * @return view
     */
    public function orderPrint($id, $type = '')
    {
        $order = $this->orderService->invoiceData($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $data['order'] = $order;
        $data['type'] = $type;
        $data['ordersideView'] = 1;

        return View::make('orders/invoice', $data);
    }

    /** Add item list in order edit.
     * @param  request (searh variables)
     * @return paginated json view
     */
    protected function productlist(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            $input['modalpaginate'] = 1;
            $input['searchterm'] = (isset($data['searchterm'])) ? $data['searchterm'] : '';
            $input['columns'] = [
                'products.supplier_code',
                'products.id',
                'products.supplier_code',
                'products.title',
                'products.variety',
                'suppliers.company_name AS supplier_name',
                'products.retail_price',
                'products.country_of_origin',
            ];
            $input['search_filters'] = '';
            $input['available'] = 1;
            $input['descriptorId'] = (isset($data['descriptorId'])) ? $data['descriptorId'] : '';
            $orders = $this->productService->getAllProductsPopUp($input);
            if ($data['type'] == 'additm') {
                return Response::json(View::make('orders.additems', [
                    'products' => $orders,
                    'hasDiscount' => $data['hasDiscount'],
                    'searchterm' => $input['searchterm'],
                ])->render());
            } elseif ($data['type'] == 'additmpaginate') {
                return Response::json(View::make('orders.additemsajax', [
                    'products' => $orders,
                    'hasDiscount' => $data['hasDiscount'],
                    'searchterm' => $input['searchterm'],
                ])->render());
            }
        }
    }

    /**
     * @param Request
     * @param order items
     * @return item total, sipping,tax etc
     */
    protected function addItemOrder(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            parse_str($data['fields'], $input);

            if (empty($input['order_items'])) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Id missing';

                return response()->json($arr);
                exit();
            }
            if (isset($data['overide_qty']) && $data['overide_qty'] == 'yes') {
                $overide_qty = true;
            } else {
                $overide_qty = false;
            }
            if (isset($data['overide_price']) && $data['overide_price'] == 'yes') {
                $overide_price = true;
            } else {
                $overide_price = false;
            }
            if (isset($data['overide_ship']) && $data['overide_ship'] == 'yes') {
                $overide_ship = true;
            } else {
                $overide_ship = false;
            }
            if (isset($data['overide_tax']) && $data['overide_tax'] == 'yes') {
                $overide_tax = true;
            } else {
                $overide_tax = false;
            }

            if (isset($data['bundle']) && $data['bundle'] == '1') {
                if (isset($data['od_id'])) {
                    $od_id = $data['od_id'];
                }
                $bArray = CommonServices::basketBundleCalculateForAjaxRequest($overide_qty, $overide_price, $overide_ship, $overide_tax, $input, $this->productService, $od_id);
            } else {
                $bArray = CommonServices::basketCalculateForAjaxRequest($overide_qty, $overide_price, $overide_ship, $overide_tax, $input, $this->productService);
            }

            return response()->json($bArray);
            exit();
        }
    }

    /**
     * @param Request
     * @param formData
     * @return view form for todos
     */
    protected function setReminder(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            parse_str($data['formData'], $input);
            if ($input['orderid'] == '' || $input['remindcontent'] == '' || $input['reminddue_on'] == '' || $input['customer_id'] == '') {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Please enter required fields';

                return response()->json($arr);
                exit();
            }
            $input['due_on'] = Carbon::now()->addMonths($input['reminddue_on']);
            $input['content'] = $input['remindcontent'];
            CommonServices::setReminder($input);
            $array['service_reminders'] = $this->orderService->getReminders($input['customer_id'], $input['task_type']);
            $html = view('todos.todos', $array);
            $arr['view'] = $html->__toString();
            $arr['success'] = 1;

            return response()->json($arr);
            exit();
        }
    }

    /**
     * @param Request
     * @param order id
     * Mail for reissue
     * @return success
     */
    protected function bookingConfirmEmail(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            if ($data['id'] == '') {
                $arr['error'] = 1;
                $arr['errormsg'] = 'id missing';

                return response()->json($arr);
                exit();
            }
            $order = $this->orderService->getOrderById($data['id']);
            if (! $order) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Order not exist';

                return response()->json($arr);
                exit();
            }
            $msg = $this->orderService->reIssueBookingConfirmation($order);
            if ($msg['success']) {
                if ($msg['email_status_success'] >= 1 && $msg['email_status_fail'] == 0) {
                    $arr['msg'] = 'Booking Confirmation email send successfully for '.$msg['email_status_success'].' booking(s)';
                } elseif ($msg['email_status_success'] >= 1 && $msg['email_status_fail'] >= 1) {
                    $arr['msg'] = 'Booking Confirmation email send successfully for '.$msg['email_status_success'].' booking(s) and failed for '.$msg['email_status_fail'].' booking(s)';
                } elseif ($msg['email_status_success'] == 0 && $msg['email_status_fail'] >= 1) {
                    $arr['msg'] = 'Booking Confirmation email send failed for '.$msg['email_status_fail'].' booking(s)';
                } else {
                    $arr['msg'] = 'Sorry, something went wrong while sending.';
                }
            } else {
                $arr['msg'] = 'Sorry, something went wrong while sending.';
            }
            $arr['success'] = 1;

            return response()->json($arr);
            exit();
        }
    }

    /** invoice pdf generation.
     * @param order id
     * @return pdf
     */
    public function invoicePDF($id)
    {
        $order = $this->orderService->getOrderById($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $file_path = $this->orderService->invoicePDF($id);

        return Response::download($file_path);
    }

    /** update debt_letter_1_sent_on to current date.
     * @param order id
     * @return view
     */
    public function debt1($id)
    {
        $order = $this->orderService->getOrderById($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $this->orderService->updateDebt1letter($id);
        if (! $order->debt_letter_1_sent_on) {
            $order->debt_letter_1_sent_on = date('Y-m-d');
        }
        $data['order'] = $order;

        return View::make('orders/debt1', $data);
    }

    /** update debt_letter_2_sent_on to current date.
     * @param order id
     * @return view
     */
    public function debt2($id)
    {
        $order = $this->orderService->getOrderById($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $ref_rate = get_boe_reference_rate(strtotime_uk($order->debt_letter_1_sent_on));
        $order->ref_rate = $ref_rate;
        if ($order->debt_letter_2_sent_on) {
            $days_late = preg_replace('/[^0-9]/', '', mydate_diff($order->due_on, $order->debt_letter_2_sent_on)) - 1;
        } else {
            $days_late = $order->days_late;
        }
        $debt_interest = (($order->grand_total * ($ref_rate + 0.08)) / 365) * $days_late;
        // Calculate compensation
        if ($order->grand_total >= 10000) {
            $debt_compensation = 100;
        } elseif ($order->grand_total >= 1000) {
            $debt_compensation = 70;
        } else {
            $debt_compensation = 40;
        }
        $this->orderService->updateDebt2letter($id, $debt_compensation, $debt_interest);
        if ($order->debt_interest <= 0) {
            $order->debt_interest = $debt_interest;
        }
        if ($order->debt_compensation <= 0) {
            $order->debt_compensation = $debt_compensation;
        }
        // Assign the date to the order
        if (! $order->debt_letter_2_sent_on) {
            $order->debt_letter_2_sent_on = date('Y-m-d');
        }
        $data['order'] = $order;

        return View::make('orders/debt2', $data);
    }

    /** pdf generation.
     * @param order id
     * @return pdf
     */
    public function debt1PDF($id)
    {
        $order = $this->orderService->getOrderById($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $file_path = $this->orderService->debt1PDF($id);

        return Response::download($file_path);
    }

    /** pdf generation.
     * @param order id
     * @return pdf
     */
    public function debt2PDF($id)
    {
        $order = $this->orderService->getOrderById($id);
        if (empty($order)) {
            return Redirect::to('orders');
        }
        $file_path = $this->orderService->debt2PDF($id);

        return Response::download($file_path);
    }

    /** feeo export page.
     * @return view
     */
    public function feefoExports()
    {
        $order = $this->orderService->orderFeefoLinks();
        $data['order'] = $order;
        $data['ordersideView'] = 1;

        return View::make('orders/feefoexport', $data);
    }

    /** feeo export csv generation.
     * @param order id
     * @return csv
     */
    public function feefoExportCsv(Request $request)
    {
        $input = $request->Input();
        //$input['paginate']       = 1;
        $input['columns'] = [
            'orders.id',
            'orders.invoice_number',
            'customers.company_name',
            'consignees.company_name AS delivery_company_name',
            'orders.payment_method',
            'orders.grand_total',
            'orders.payment_status',
            'orders.percent_packed',
            'orders.created_on',
            //'users.username AS agent',
            'customers.courtesy_title',
            'customers.first_name',
            'customers.last_name',
            'customers.address_1',
            'customers.address_2',
            'customers.town',
            'customers.county',
            'customers.country_id',
            'customers.account_number',
            'customers.postcode',
            'consignees.first_name AS delivery_first_name',
            'consignees.last_name AS delivery_last_name',
            'consignees.address_1 AS delivery_address_1',
            'consignees.address_2 AS delivery_address_2',
            'consignees.town AS delivery_town',
            'consignees.county AS delivery_county',
            'consignees.country_id AS delivery_country_id',
            'consignees.postcode AS delivery_postcode',
            'orders.net_total',
            'orders.shipping_total',
            'orders.tax_total',
            'orders.cheque_number',
            'customers.email',
            'orders.discount_total',
            'orders.paid_on', 'orders.agent_id', 'orders.payment_status',
        ];
        $input['search_filters'] = $this->searchFields();
        $orders = $this->orderService->getAllOrders($input);
        $tmp = explode('-', $input['f']['date_paid']);
        $d = strtotime_uk($tmp[0]);
        $filename = 'feefo-orders-'.date('Y-m', strtotime_uk($tmp[0]));

        $websites = [
            'MYHS' => 'http://www.myhealthystaff.com',
            'OSC' => 'http://www.myhealthystaff.com',
            'FEC' => 'http://www.fire-safety-evacuation.co.uk',
            'OSS' => 'http://www.onsite-safety.co.uk',
            'BSS' => 'http://www.businesssafety.co.uk',
        ];
        $output = [];
        if (! empty($orders)) {
            foreach ($orders as $row) {
                // Tidied name
                $name = str_concat_no_empty(' ', $row->courtesy_title, $row->first_name, $row->last_name);
                $code = substr($row->invoice_number, 0, 3);
                $website = isset($websites[$code]) ? $websites[$code] : '';
                $order_items = $this->orderService->getAllOrderItemsByOrderid($row->id);
                if (! empty($order_items)) {
                    foreach ($order_items as $item) {
                        $output[] = [
                            'Name' => ($name) ? TrimStr($name) : '--',
                            'Email' => ($row->email) ? TrimStr($row->email) : '--',
                            'Date' => ($row->paid_on != null && $row->paid_on != '0000-00-00 00:00:00') ? mydate_format($row->paid_on) : '--',
                            'Description' => ($item->title) ? TrimStr($item->title).($item->variety ? ' '.$item->variety : '') : '--',
                            'Logon' => 'www.hs-group.com/'.strtolower($code),
                            'Category' => ($code) ? ($code) : '--',
                            'Product Search Code' => ($item->supplier_code) ? ($item->supplier_code) : '--',
                            'Order Ref' => ($row->invoice_number) ? TrimStr($row->invoice_number) : '--',
                            'Product Link' => $website.'/p/'.$item->product_id.'/',
                            'Customer Ref' => ($row->account_number) ? ($row->account_number) : '--',
                            'Amount' => ($item->paid_price) ? ($item->paid_price) : '--',
                        ];
                    }
                }
            }
        } else {
            $output[] = [
                'No records found',
            ];
        }

        return $this->simpleExport($output, $filename, 'csv', 'productsheet');
        exit;
    }

    /** unpaid sales page.
     * @return view
     */
    public function unpaidsalesinvoicereports(Request $request)
    {
        $input = $request->Input();
        $input['paginate'] = 1;
        $input['search_filters'] = $data['search_filters'] = $this->unpaid_sales_invoice_reports_column_map();
        $unpaid_invoice_reports = $this->orderService->getAllUnpaidsalesReport($input);
        $data['unpaid_invoice_reports'] = $unpaid_invoice_reports;
        $data['ordersideView'] = 1;

        return View::make('orders/unpaidsales', $data);
    }

    /**
     * @data['company name']
     * @return error term not set
     * @return  query result not found
     * @return success
     */
    protected function companySearch(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            if (! isset($data['term'])) {
                $arr['error'] = 1;
                $arr['errormsg'] = 'Null value';

                return response()->json($arr);
                exit();
            }
            $array = [];
            $str = urldecode($data['term']);
            $result = $this->customerService->customerSearchForOrder($str);
            if (! empty($result) && count($result)) {
                foreach ($result as $row) {
                    $field = '';
                    $field = ($row['account_number']) ? $row['account_number'].'<br>' : '';
                    $field .= ($row['courtesy_title']) ? $row['courtesy_title'].' ' : '';
                    $field .= ($row['first_name']) ? $row['first_name'].' ' : '';
                    $field .= ($row['last_name']) ? $row['last_name'].'<br>' : '<br>';
                    $field .= ($row['company_name']) ? $row['company_name'].'<br>' : '';
                    $field .= ($row['address_1']) ? $row['address_1'].',' : '';
                    //$field .= ($row['address_2'])?$row['address_2'].',':'';
                    $field .= ($row['town']) ? $row['town'].',' : '';
                    $field .= ($row['postcode']) ? $row['postcode'] : '';
                    $field .= ($row['phone']) ? '<br>'.$row['phone'] : '';
                    $array[] = [
                        'id' => $row->id,
                        'value' => $field,
                    ];
                }
            } else {
                $array[] = [
                    'id' => '',
                    'value' => 'No records found',
                ];
            }

            return response()->json($array);
            exit();
        }
    }

    protected function getCustomerDetailsForOrder(Request $request)
    {
        if ($request->ajax()) {
            $data = $request->all();
            $result = $this->customerService->getCustomerById($data['id'])->toArray();

            return $result;
            exit();
        }
    }

    protected function unpaid_sales_invoice_reports_column_map()
    {
        return [
            'report_date' => [
                'column' => 'unpaid_sales_invoice_reports.report_date',
                'label' => 'Report Date',
                'type' => 'date',
            ],
        ];
    }

    protected function searchFields()
    {
        $agent_options = [];
        $agents = CommonServices::getAgents();
        $agent_options = [];
        if (! empty($agents)) {
            foreach ($agents as $a) {
                $agent_options[$a->id] = ucfirst($a->username);
            }
        }
        $country_options = [];
        $countries = CommonServices::getCountries();
        if (! empty($countries)) {
            foreach ($countries as $a) {
                $country_options[$a->id] = ucfirst($a->name);
            }
        }

        $products = CommonServices::getAllProductTitle();
        $product_options = [];
        if (! empty($products)) {
            foreach ($products as $a) {
                $product_options[$a->id] = ucfirst($a->title);
            }
        }

        return [
            'agent' => [
                'column' => 'orders.agent_id',
                'label' => 'Agent',
                'type' => 'select',
                'options' => $agent_options,
                'use_keys_for_values' => true,
            ],
            'company' => [
                'column' => 'customers.company_name',
                'label' => 'Customer Company',
                'type' => 'text',
            ],
            'last_name' => [
                'column' => 'customers.last_name',
                'label' => 'Customer Last name',
                'type' => 'text',
            ],
            'postcode' => [
                'column' => 'customers.postcode',
                'label' => 'Customer Postcode',
                'type' => 'text',
            ],
            'purchase_order' => [
                'column' => 'orders.purchase_order',
                'label' => 'Purchase order',
                'type' => 'text',
            ],
            'delivery_company' => [
                'column' => 'consignees.company_name',
                'label' => 'Delivery Company',
                'type' => 'text',
            ],
            'delivery_last_name' => [
                'column' => 'consignees.last_name',
                'label' => 'Delivery Last name',
                'type' => 'text',
            ],
            'delivery_postcode' => [
                'column' => 'consignees.postcode',
                'label' => 'Delivery Postcode',
                'type' => 'text',
            ],
            'customer_type' => [
                'column' => 'customers.type',
                'label' => 'Customer type',
                'type' => 'select',
                'options' => CommonServices::getEnumOptions('customers', 'type'),
            ],
            'customer_mail_opt_in' => [
                'column' => 'customers.mail_opt_in',
                'label' => 'Customer mail opt-in',
                'type' => 'number',
            ],
            'net_total' => [
                'column' => 'orders.net_total',
                'label' => 'Net total',
                'type' => 'number',
            ],
            'payment_method' => [
                'column' => 'orders.payment_method',
                'label' => 'Payment method',
                'type' => 'select',
                'options' => CommonServices::getEnumOptions('orders', 'payment_method'),
            ],
            'payment_status' => [
                'column' => 'orders.payment_status',
                'label' => 'Payment status',
                'type' => 'select',
                'options' => [
                    'Provisional',
                    'Due',
                    'Paid',
                    'Cancelled',
                ],
            ],
            'percent_packed' => [
                'column' => 'orders.percent_packed',
                'label' => 'Percent Packed',
                'type' => 'number',
            ],
            'cheque_number' => [
                'column' => 'orders.cheque_number',
                'label' => 'Credit slip number',
                'type' => 'text',
            ],
            'date_created' => [
                'column' => 'orders.created_on',
                'label' => 'Date created',
                'type' => 'date',
            ],
            'date_created_range' => [
                'column' => 'orders.created_on',
                'label' => 'Date created Range',
                'type' => 'daterange',
            ],
            'date_invoiced' => [
                'column' => 'orders.invoiced_on',
                'label' => 'Date invoiced',
                'type' => 'date',
            ],
            'date_invoiced_range' => [
                'column' => 'orders.invoiced_on',
                'label' => 'Date invoiced Range',
                'type' => 'daterange',
            ],
            'date_due' => [
                'column' => 'orders.due_on',
                'label' => 'Date due',
                'type' => 'date',
            ],
            'date_due_range' => [
                'column' => 'orders.due_on',
                'label' => 'Date due Range',
                'type' => 'daterange',
            ],
            'date_paid' => [
                'column' => 'orders.paid_on',
                'label' => 'Date paid',
                'type' => 'date',
            ],
            'date_paid_range' => [
                'column' => 'orders.paid_on',
                'label' => 'Date paid Range',
                'type' => 'daterange',
            ],
            'date_cancelled' => [
                'column' => 'orders.cancelled_on',
                'label' => 'Date cancelled',
                'type' => 'daterange'
            ],
            'date_baddebt' => [
                'column' => 'orders.baddebt_on',
                'label' => 'Date bad debt on',
                'type' => 'date',
            ],
            'date_debt_letter_1' => [
                'column' => 'orders.debt_letter_1_sent_on',
                'label' => 'Date debt letter 1 sent',
                'type' => 'date',
            ],
            'date_debt_letter_2' => [
                'column' => 'orders.debt_letter_2_sent_on',
                'label' => 'Date debt letter 2 sent',
                'type' => 'date',
            ],
            'contains_products' => [
                'column' => 'products.supplier_code',
                'label' => 'Contains product code',
                'type' => 'text',
            ],
            'contains_product_type' => [
                'column' => 'products.type',
                'label' => 'Contains product type',
                'type' => 'text',
            ],
            'country_id' => [
                'column' => 'customers.country_id',
                'label' => 'Customer Country',
                'type' => 'select',
                'options' => $country_options,
                'use_keys_for_values' => true,
            ],
            'code' => [
                'column' => 'discount_codes.code',
                'label' => 'Discount Code',
                'type' => 'text',
            ],
            'invoice_number' => [
                'column' => 'orders.invoice_number',
                'label' => 'Order Id',
                'type' => 'text',
            ],
            'product_title' => [
                'column' => 'product_descriptors.id',
                'label' => 'Product',
                'type' => 'select',
                'options' => $product_options,
                'use_keys_for_values' => true,
            ],
            'cpd_discount_code' => [
                'column' => 'orders.cpdDiscountCode',
                'label' => 'CPD Discount Code',
                'type' => 'select',
                'options' => ['Code Used', 'No Code Used'],
                'default' => '',
                'use_keys_for_values' => true,
            ],
            'refunded' => [
                'column' => 'orders.refunded',
                'label' => 'Refunded',
                'type' => 'select',
                'options' => [
                    'no' => 'No',
                    'yes' => 'Yes',
                ],
                'use_keys_for_values' => true,
            ],
            'provisional_due' => [
                'column' => 'orders.payment_status',
                'label' => 'Provisional Orders to Followup',
                'type' => 'select',
                'options' => [
                    'WEEK_DUE' => 'A single provisional order, with no corresponding paid order, within a week',
                    'TWO_DAYS_DUE' => 'Two provisional orders, with no corresponding paid order, within two days',
                ],
                'use_keys_for_values' => true,
            ],
            'pdi_product' => [
                'column' => 'products.pdi_dispatch_automatically',
                'label' => 'PDI Product',
                'type' => 'checkbox'
            ]
        ];

    }

    public function exportOrderProductDetail(Request $request)
    {
        $productDetails = $this->orderService->getProductDetailsByOrder($request);

        $out = [];

        foreach ($productDetails as $productDetail) {
            $out[] = [
                'Order no' => $productDetail->id,
                'Invoice no' => $productDetail->invoice_number,
                'product code' => $productDetail->supplier_code,
                'product details' => $productDetail->title,
                'quantity' => $productDetail->quantity,
                'amount per item' => $productDetail->paid_price,
                'total(net)' => $productDetail->paid_price * $productDetail->quantity,
                'tax per item' => $productDetail->paid_price * $productDetail->tax / 100,
                'company name' => $productDetail->company_name,
                'invoice_date' => ($productDetail->invoiced_on) ? Carbon::createFromFormat('Y-m-d H:i:s', $productDetail->invoiced_on)->format('d/m/Y') : '',
                'due date' => ($productDetail->due_on) ? Carbon::createFromFormat('Y-m-d H:i:s', $productDetail->due_on)->format('d/m/Y') : '',
                'date paid' => ($productDetail->paid_on) ? Carbon::createFromFormat('Y-m-d H:i:s', $productDetail->paid_on)->format('d/m/Y') : '',
            ];
        }

        return $this->simpleExport($out, 'Order Product Details', 'csv', 'order_product_details');
    }

    public function redirectRouteYear()
    {
        $year = date('d/m/Y');

        return redirect('orders?f[date_created]='.$year.'&advance_search_disable=1');
    }
    
    public function searchById(Request $request,$id =null)
    {
        return $this->orderService->getSearchById($request,$id);
    }
}
