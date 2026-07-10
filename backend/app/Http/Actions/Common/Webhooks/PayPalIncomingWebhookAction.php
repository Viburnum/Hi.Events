<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Order\Payment\PayPal\DTO\PayPalWebhookDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\PayPal\IncomingWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class PayPalIncomingWebhookAction extends BaseAction
{
    public function __invoke(Request $request): Response
    {
        try {
            $payload = $request->getContent();
            $headers = [
                'paypal-transmission-id' => $request->header('paypal-transmission-id'),
                'paypal-transmission-time' => $request->header('paypal-transmission-time'),
                'paypal-transmission-sig' => $request->header('paypal-transmission-sig'),
                'paypal-cert-url' => $request->header('paypal-cert-url'),
                'paypal-auth-algo' => $request->header('paypal-auth-algo'),
            ];

            dispatch(static function (IncomingWebhookHandler $handler) use ($headers, $payload) {
                $handler->handle(new PayPalWebhookDTO(
                    headers: $headers,
                    payload: $payload,
                ));
            })->catch(function (Throwable $exception) use ($payload) {
                logger()->error(__('Failed to handle incoming PayPal webhook'), [
                    'exception' => $exception,
                    'payload' => $payload,
                ]);
            });

        } catch (Throwable $exception) {
            logger()?->error($exception->getMessage(), $exception->getTrace());

            return $this->noContentResponse(ResponseCodes::HTTP_BAD_REQUEST);
        }

        return $this->noContentResponse();
    }
}
