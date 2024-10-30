<?php

use App\Events\PaymentEvent;
use App\Events\UserUpdateCreditsEvent;
use App\Models\PartnerDiscount;
use App\Models\Payment;
use App\Models\ShopProduct;
use App\Models\User;
use App\Notifications\ConfirmPaymentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * @param Request $request
 * @param ShopProduct $shopProduct
 */
function MercadoPagoPay(Request $request)
{
    /** @var User $user */
    $user = Auth::user();
    $shopProduct = ShopProduct::findOrFail($request->shopProduct);
    $discount = PartnerDiscount::getDiscount();

    // create a new payment server
    $payment = Payment::create([
        'user_id' => $user->id,
        'payment_id' => null,
        'payment_method' => 'mercadopago',
        'type' => $shopProduct->type,
        'status' => 'open',
        'amount' => $shopProduct->quantity,
        'price' => $shopProduct->price - ($shopProduct->price * $discount / 100),
        'tax_value' => $shopProduct->getTaxValue(),
        'tax_percent' => $shopProduct->getTaxPercent(),
        'total_price' => $shopProduct->getTotalPrice(),
        'currency_code' => $shopProduct->currency_code,
        'shop_item_product_id' => $shopProduct->id,
    ]);

    try {
        //basic restriction
        if (!str_contains(config('app.url'), 'https://')) {
            $payment->delete();
            return Redirect::route('store.index')->with('error', __('It is not possible to purchase via MercadoPago: APP_URL does not have HTTPS, required by Mercado Pago'))->send();
        }
        $url = 'https://api.mercadopago.com/checkout/preferences';
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . config('SETTINGS::PAYMENTS:MPAGO:ACCESS_TOKEN'),
        ])->post($url, [
                    'back_urls' => [
                        'success' => route('payment.MercadoPagoChecker'),
                        'failure' => route('payment.Cancel'),
                        'pending' => route('payment.MercadoPagoChecker'),
                    ],
                    'notification_url' => route('payment.MercadoPagoIPN'),
                    'payer' => [
                        'email' => $user->email,
                    ],
                    'items' => [
                        [
                            'title' => $shopProduct->display . ($discount ? (" (" . __('Discount') . " " . $discount . '%)') : ""),
                            'quantity' => 1,
                            // convert to float
                            'unit_price' => floatval($shopProduct->getTotalPrice()),
                            'currency_id' => $shopProduct->currency_code,
                        ],
                    ],
                    'metadata' => [
                        'credit_amount' => $shopProduct->quantity,
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'crtl_panel_payment_id' => $payment->id,
                    ],
                ]);
        if ($response->successful()) {
            // Redirect link
            Redirect::to($response->json()['init_point'])->send();
        } else {
            Log::error('MercadoPago Payment: ' . $response->body());
            throw new Exception('Payment failed');
        }
    } catch (Exception $ex) {
        Log::error('Mercado Pago Payment: ' . $ex->getMessage());
        $payment->delete();

        Redirect::route('store.index')->with('error', __('Payment failed'))->send();
        return;
    }
}

/**
 * Mercado Pago Primary Response Checker
 * @param Request $laravelRequest
 */
function MercadoPagoChecker(Request $laravelRequest)
{
    $user = Auth::user();
    $user = User::findOrFail($user->id);

    try {
        // paymentID (not is preferenceID or paymentID for store)
        $paymentId = $laravelRequest->input('payment_id');
        $MpPayment = MpPayment($paymentId, false);

        switch ($MpPayment) {
            case "paid":
                Redirect::route('home')->with('success', 'Payment successful')->send();
                break;
            case "cancelled":
                Redirect::route('home')->with('info', 'Your canceled the payment')->send();
                break;
            case "processing":
                Redirect::route('home')->with('info', 'Your payment is being processed')->send();
                break;
            default:
                Redirect::route('home')->with('error', 'Your payment is unknown')->send();
                break;
        }
    } catch (Exception $ex) {
        Log::error('Mercado Pago Payment: ' . $ex->getMessage());
        abort(500);
    }
}


/**
 * Mercado pago Webhook Configurations
 * @param Request $laravelRequest
 */
function MercadoPagoIPN(Request $laravelRequest)
{
    $topic = $laravelRequest->input('topic');
    $action = $laravelRequest->input('action');

    if ($topic === 'merchant_order') {
        $status = 200;
    } else if ($topic === 'payment') {
        $status = 200;
    } else {
        try {
            Log::info('MP_Payment:', ['data' => $laravelRequest]);
            if ($action && $action == "payment.created") {
                $notification = $laravelRequest['data']['id'];
                if (!$notification)
                    return response()->json(['success' => false], 400);
                Log::info('MP_Payment Created ID:', ['id' => $notification]);
                $url = "https://api.mercadopago.com/v1/payments/" . $notification;
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . config('SETTINGS::PAYMENTS:MPAGO:ACCESS_TOKEN'),
                ])->get($url);
                if ($response->successful()) {
                    $mercado = $response->json();
                    $payment = Payment::findOrFail($mercado['metadata']['crtl_panel_payment_id']);
                    $payment->update([
                        'status' => "created",
                        'payment_id' => $notification,
                    ]);
                    return response()->json(['success' => true], 200);
                } else {
                    return response()->json(['success' => false], 500);
                }
            } else {
                $notification = $laravelRequest['data']['id'];
                Log::info('MP_Payment Action ID:', ['id' => $notification]);
                if (!$notification)
                    return response()->json(['success' => false], 400);
                if ($notification == '123456')
                    return response()->json(['success' => true], 200);
                $url = "https://api.mercadopago.com/v1/payments/" . $notification;
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . config('SETTINGS::PAYMENTS:MPAGO:ACCESS_TOKEN'),
                ])->get($url);


                if ($response->successful()) {
                    $mercado = $response->json();
                    $user = User::findOrFail($mercado['metadata']['user_id']);
                    $status = $mercado['status'];
                    $payment = Payment::findOrFail($mercado['metadata']['crtl_panel_payment_id']);
                    $shopProduct = ShopProduct::findOrFail($payment->shop_item_product_id);
                    Log::info('MP_Payment Status :', ['status' => $status]);
                    Log::info('Panel_Payment Status :', ['status' => $payment->status]);

                    if ($status === 'approved') {
                        if ($payment->status !== 'paid' && $payment->status !== 'cancelled') {
                            $payment->update([
                                'status' => 'paid',
                                'payment_id' => $notification,
                            ]);

                            event(new UserUpdateCreditsEvent($user));
                            event(new PaymentEvent($user, $payment, $shopProduct));
                            $user->notify(new ConfirmPaymentNotification($payment));
                        }

                    } else {
                        if ($status == "cancelled") {
                            $user = User::findOrFail($payment->user_id);
                            $payment->update([
                                'status' => "cancelled",
                                'payment_id' => $notification,
                            ]);
                            $payment->save();
                            event(new PaymentEvent($user, $payment, $shopProduct));
                        } else {
                            $payment->update([
                                'status' => "processing",
                                'payment_id' => $notification,
                            ]);
                            event(new PaymentEvent($user, $payment, $shopProduct));
                        }
                    }
                } else {
                    return response()->json(['success' => false], 500);
                }
            }
        } catch (\Exception $e) {
            Log::error('Mercado Pago Payment IPN: ' . $e->getMessage());
            $status = 401;
        }
    }
    if ($status === 200) {
        return response()->json(['success' => true], 200);
    } else {
        return response()->json(['success' => false], $status);
    }
}

/**
 * Mercado pago Payment Checker and Requester
 */
function MpPayment(string $paymentID, bool $notification)
{

    $user = Auth::user();
    $user = User::findOrFail($user->id);
    $MpResponse = "unknown";

    $url = "https://api.mercadopago.com/v1/payments/" . $paymentID;

    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . config('SETTINGS::PAYMENTS:MPAGO:ACCESS_TOKEN'),
    ])->get($url);

    if ($response->successful()) {
        $mercado = $response->json();
        $status = $mercado['status'];
        $payment = Payment::findOrFail($mercado['metadata']['crtl_panel_payment_id']);
        $shopProduct = ShopProduct::findOrFail($payment->shop_item_product_id);

        if ($status === 'approved') {
            $MpResponse = "paid";
            if ($payment->status !== 'paid') {
                $payment->update([
                    'status' => 'paid',
                    'payment_id' => $paymentID,
                ]);
                event(new UserUpdateCreditsEvent($user));
                event(new PaymentEvent($user, $payment, $shopProduct));
                if ($notification) {
                    $user->notify(new ConfirmPaymentNotification($payment));
                }
            }
        } else {
            if ($status == "cancelled") {
                $user = User::findOrFail($payment->user_id);
                $payment->update([
                    'status' => "cancelled",
                    'payment_id' => $paymentID,
                ]);
                $payment->save();
                event(new PaymentEvent($user, $payment, $shopProduct));
                $MpResponse = "cancelled";
            } else {
                $MpResponse = "processing";
                $payment->update([
                    'status' => "processing",
                    'payment_id' => $paymentID,
                ]);
                event(new PaymentEvent($user, $payment, $shopProduct));
            }
        }
    }
    return $MpResponse;
}