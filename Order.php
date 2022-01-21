/*
* This is model of Order
* This file used for order operation like order save, edit etc
* Here defiend EnterpriseClient table column name with database query
* This is php laravel freamwork
*/

<?php

namespace App\Models;

use App\Traits\ModelEventLogger;
use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Schema; // for activity table

class Order extends Model
{
    /*
     * The database table used by the model.
     *
     * @var string
     */
    use ModelEventLogger; // for activity table
    protected $table = 'orders';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the Order items.
     */
    public function order_items()
    {
        return $this->hasMany(\App\Models\OrderItem::class, 'order_id', 'id');
    }

    /**
     * Get the Customer.
     */
    public function customer()
    {
        return $this->hasOne(\App\Models\Customer::class, 'id', 'customer_id');
    }

    /**
     * Get Consignee details.
     */
    public function consignee()
    {
        return $this->hasOne(\App\Models\Consignee::class, 'id', 'consignee_id');
    }

    public function getColumnlist()
    {
        return $this->getConnection()->getSchemaBuilder()->getColumnListing($this->getTable());
    }

    /**
     * Get Trainee
     */
    public function trainee()
    {
        return $this->hasOne('App\Models\Trainees', 'order_id', 'id');
    } 
    
    public function scopePaidOnIsPresent($query,$paid_on_like,$paid_on_group)
    {
        if ($paid_on_like != '') {
            $query->where('paid_on', 'like', $paid_on_like.'%');
        }

        return $query->groupBy(DB::raw('DATE_FORMAT(paid_on, "'.$paid_on_group.'")'))->orderBy('paid_on', 'ASC');
    }

    public function scopeCheckDateRangeIsPresent($query, $date_range, $paid_on_like, $sales_period, $sales_period_end)
    {
        if ($date_range) {
            $query->where(DB::raw('DATE(paid_on)  BETWEEN "'.$sales_period.'" AND "'.$sales_period_end.'") '));
        } elseif ($paid_on_like) {
            $query->where('paid_on', 'like', $paid_on_like.'%');
        }

        return $query->groupBy('source')->orderBy('net_total', 'DESC');
    }

    public function scopeCheckAgencyDateRangeIsPresent($query, $date_range, $paid_on_like, $sales_period, $sales_period_end)
    {
        if ($date_range) {
            $query->where(DB::raw('DATE(paid_on)  BETWEEN "'.$sales_period.'" AND "'.$sales_period_end.'") '));
        } elseif ($paid_on_like) {
            $query->where('paid_on', 'like', $paid_on_like.'%');
        }

        return $query->groupBy('users.id')->orderBy('users.username', 'ASC');
    }

    public function scopeCheckPaymentMethodDateRangeIsPresent($query, $date_range, $paid_on_like, $sales_period, $sales_period_end)
    {
        if ($date_range) {
            $query->where(DB::raw('DATE(paid_on)  BETWEEN "'.$sales_period.'" AND "'.$sales_period_end.'") '));
        } elseif ($paid_on_like) {
            $query->where('paid_on', 'like', $paid_on_like.'%');
        }

        return $query->groupBy('payment_method')->orderBy('net_total', 'DESC');
    }

    public function scopeCheckSupplierDateRangeIsPresent($query, $date_range, $paid_on_like, $sales_period, $sales_period_end)
    {
        if ($date_range) {
            $query->where(DB::raw('DATE(paid_on)  BETWEEN "'.$sales_period.'" AND "'.$sales_period_end.'") '));
        } elseif ($paid_on_like) {
            $query->where('paid_on', 'like', $paid_on_like.'%');
        }

        return $query->groupBy('suppliers.id')->orderBy('net_total', 'DESC');
    }

    public function scopeCheckAgencyGraphDateRangeIsPresent($query, $date_range, $paid_on_like)
    {
        if ($date_range) {
            $date_range_exp = explode('-', $date_range);
            $date_range_start = mydate_format($date_range_exp[0], 'Y-m-d');
            $date_range_end = mydate_format($date_range_exp[1], 'Y-m-d');

            $query->whereBetween('paid_on', [$date_range_start, $date_range_end]);
        }
        if ($paid_on_like) {
            $query->where('paid_on', 'LIKE', $paid_on_like.'%');
        }

        return $query;
    }

    public function activityLog($input, $data = null)
    {
        $activity = [];
        $fieldArray = [
            'invoice_note' => 'Additonal notes',
            'agent_id' => 'Agent',
            'payment_method' => 'Payment Method',
            'purchase_order' => 'Purchase Order',
            'cheque_number' => 'Slip Number',
            'trans[transId]' => 'WorldPay Trans Id',
            'invoiced_on' => 'Invoiced On',
            'due_on' => 'Due On',
            'paid_on' => 'Paid On',
            'debt_letter_1_sent_on' => 'Debt letter 1 sent on',
            'debt_letter_2_sent_on' => 'Debt letter 2 sent on',
            'debt_interest' => 'Interest',
            'debt_compensation' => 'Compensation',
            'pack_reference' => 'Packing Reference Number',
            'company_type' => 'Mailing Preferences',
            'shipping_total' => 'Shipping Total',
            'tax_total' => 'Tax Total',
            'net_total' => 'Net Total',
            'grand_total' => 'Grand Total',
            'customer_id' => 'Customer',
            'consignee_id' => 'Consignee',
            'cancelled_on' => 'Cancelled On',
            'cancelled_by' => 'Cancelled By',
            'refunded' => 'Refunded',
            'refunded_date' => 'Refunded date',

        ];

        foreach ($input as $key => $value) {
            if (isset($fieldArray[$key])) {
                $activity[$fieldArray[$key]] = $value;
            } else {
                $activity[$key] = $value;
            }
        }

        if (isset($input['invoiced_on'])) {
            if ($data && $input['invoiced_on'] != date('Y-m-d', strtotime($data['invoiced_on']))) {
                $activity['Invoiced On'] = (isset($input['invoiced_on']) && $input['invoiced_on'] != 'NULL' && $input['invoiced_on'] != '') ? date('d/m/Y', strtotime($input['invoiced_on'])) : '';
            } elseif (! $data && $input['invoiced_on']) {
                $activity['Invoiced On'] = (isset($input['invoiced_on']) && $input['invoiced_on'] != 'NULL' && $input['invoiced_on'] != '') ? date('d/m/Y', strtotime($input['invoiced_on'])) : '';
            } else {
                unset($activity['Invoiced On']);
            }
        }
        if (isset($input['due_on'])) {
            if ($data && $input['due_on'] != date('Y-m-d', strtotime($data['due_on']))) {
                $activity['Due On'] = (isset($input['due_on']) && $input['due_on'] != 'NULL' && $input['due_on'] != '') ? date('d/m/Y', strtotime($input['due_on'])) : '';
            } elseif (! $data && $input['due_on']) {
                $activity['Due On'] = (isset($input['due_on']) && $input['due_on'] != 'NULL' && $input['due_on'] != '') ? date('d/m/Y', strtotime($input['due_on'])) : '';
            } else {
                unset($activity['Due On']);
            }
        }
        if (isset($input['paid_on'])) {
            if ($data && $input['paid_on'] != date('Y-m-d', strtotime($data['paid_on']))) {
                $activity['Paid On'] = (isset($input['paid_on']) && $input['paid_on'] != 'NULL' && $input['paid_on'] != '') ? date('d/m/Y', strtotime($input['paid_on'])) : '';
            } elseif (! $data && $input['paid_on']) {
                $activity['Paid On'] = (isset($input['paid_on']) && $input['paid_on'] != 'NULL' && $input['paid_on'] != '') ? date('d/m/Y', strtotime($input['paid_on'])) : '';
            } else {
                unset($activity['Paid On']);
            }
        }
        if (isset($input['debt_letter_1_sent_on'])) {
            if ($data && $input['debt_letter_1_sent_on'] != date('Y-m-d', strtotime($data['debt_letter_1_sent_on']))) {
                $activity['Debt letter 1 sent on'] = (isset($input['debt_letter_1_sent_on']) && $input['debt_letter_1_sent_on'] != 'NULL' && $input['debt_letter_1_sent_on'] != '') ? date('d/m/Y', strtotime($input['debt_letter_1_sent_on'])) : '';
            } elseif (! $data && $input['debt_letter_1_sent_on']) {
                $activity['Debt letter 1 sent on'] = (isset($input['debt_letter_1_sent_on']) && $input['debt_letter_1_sent_on'] != 'NULL' && $input['debt_letter_1_sent_on'] != '') ? date('d/m/Y', strtotime($input['debt_letter_1_sent_on'])) : '';
            } else {
                unset($activity['Debt letter 1 sent on']);
            }
        }
        if (isset($input['debt_letter_2_sent_on'])) {
            if ($data && $input['debt_letter_2_sent_on'] != date('Y-m-d', strtotime($data['debt_letter_2_sent_on']))) {
                $activity['Debt letter 2 sent on'] = (isset($input['debt_letter_2_sent_on']) && $input['debt_letter_2_sent_on'] != 'NULL' && $input['debt_letter_2_sent_on'] != '') ? date('d/m/Y', strtotime($input['debt_letter_2_sent_on'])) : '';
            } elseif (! $data && $input['debt_letter_2_sent_on']) {
                $activity['Debt letter 2 sent on'] = (isset($input['debt_letter_2_sent_on']) && $input['debt_letter_2_sent_on'] != 'NULL' && $input['debt_letter_2_sent_on'] != '') ? date('d/m/Y', strtotime($input['debt_letter_2_sent_on'])) : '';
            } else {
                unset($activity['Debt letter 2 sent on']);
            }
        }
        if (isset($input['agent_id']) && $input['agent_id']) {
            $activity['Agent'] = DB::table('users')->find($input['agent_id'])->username;
        }
        if (isset($input['customer_id']) && $input['customer_id']) {
            $activity['Customer'] = DB::table('customers')->find($input['customer_id'])->company_name;
        }
        if (isset($input['consignee_id']) && $input['consignee_id'] && $input['consignee_id'] != 'NULL') {
            $activity['Consignee'] = DB::table('consignees')->find($input['consignee_id'])->company_name;
        }
        if (isset($input['cancelled_by']) && $input['cancelled_by']) {
            $activity['Cancelled By'] = DB::table('users')->find($input['cancelled_by'])->username;
        }

        if (isset($input['refunded'])) {
            if ($data && $input['refunded'] != $data['refunded']) {
                $activity['Refunded'] = ($input['refunded'] == 'yes') ? 'Yes' : 'No';
            } elseif (! $data && $input['refunded']) {
                $activity['Refunded'] = ($input['refunded'] == 'yes') ? 'Yes' : 'No';
            } else {
                unset($activity['Refunded']);
            }
        }
        if (isset($activity['Refunded']) && isset($input['refunded_date'])) {
            $activity['Refunded date'] = date('d/m/Y', strtotime($input['refunded_date']));
        }

        ksort($activity);

        return $activity;
    }
}
