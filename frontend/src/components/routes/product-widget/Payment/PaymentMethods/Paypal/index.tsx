import {useNavigate, useParams} from "react-router";
import {useEffect, useState} from "react";
import {PayPalButtons, PayPalScriptProvider} from "@paypal/react-paypal-js";
import {useCreatePaypalOrder} from "../../../../../../queries/useCreatePaypalOrder.ts";
import {useCapturePaypalOrder} from "../../../../../../mutations/useCapturePaypalOrder.ts";
import {useGetEventPublic} from "../../../../../../queries/useGetEventPublic.ts";
import {CheckoutContent} from "../../../../../layouts/Checkout/CheckoutContent";
import {HomepageInfoMessage} from "../../../../../common/HomepageInfoMessage";
import {LoadingMask} from "../../../../../common/LoadingMask";
import {t} from "@lingui/macro";
import {eventCheckoutPath, eventHomepagePath} from "../../../../../../utilites/urlHelper.ts";
import {showError} from "../../../../../../utilites/notifications.tsx";
import {isSsr} from "../../../../../../utilites/helpers.ts";
import {Event} from "../../../../../../types.ts";

interface PaypalPaymentMethodProps {
    enabled: boolean;
}

export const PaypalPaymentMethod = ({enabled}: PaypalPaymentMethodProps) => {
    const {eventId, orderShortId} = useParams();
    const navigate = useNavigate();
    const {data: event} = useGetEventPublic(eventId);
    const {
        data: paypalData,
        isFetched: isPaypalFetched,
        error: paypalOrderError
    } = useCreatePaypalOrder(eventId, orderShortId, enabled && !isSsr());
    const capturePaypalOrderMutation = useCapturePaypalOrder();
    const [isMounted, setIsMounted] = useState(false);

    useEffect(() => {
        setIsMounted(true);
    }, []);

    if (!enabled) {
        return (
            <CheckoutContent>
                <HomepageInfoMessage
                    status="warning"
                    message={t`Payments not available`}
                    subtitle={t`PayPal payments are not enabled for this event.`}
                    link={eventHomepagePath(event as Event)}
                    linkText={t`Return to Event`}
                />
            </CheckoutContent>
        );
    }

    if (paypalOrderError && event) {
        return (
            <CheckoutContent>
                <HomepageInfoMessage
                    status="error"
                    /* @ts-ignore */
                    message={paypalOrderError.response?.data?.message || t`Something went wrong`}
                    subtitle={t`Please restart the checkout process.`}
                    link={eventHomepagePath(event)}
                    linkText={t`Return to Event`}
                />
            </CheckoutContent>
        );
    }

    if (isSsr() || !isMounted || !isPaypalFetched || !paypalData?.client_id) {
        return <LoadingMask/>;
    }

    return (
        <div>
            <h2>{t`Payment`}</h2>
            <PayPalScriptProvider
                options={{
                    clientId: paypalData.client_id,
                    currency: paypalData.currency,
                    intent: "capture",
                }}
            >
                <PayPalButtons
                    style={{layout: "vertical"}}
                    createOrder={() => Promise.resolve(paypalData.paypal_order_id)}
                    onApprove={async () => {
                        try {
                            await capturePaypalOrderMutation.mutateAsync({
                                eventId,
                                orderShortId,
                                paypalOrderId: paypalData.paypal_order_id,
                            });
                            navigate(eventCheckoutPath(eventId, orderShortId, "payment_return"));
                        } catch (error: any) {
                            showError(error?.response?.data?.message || t`PayPal payment failed. Please try again or contact the event organizer.`);
                        }
                    }}
                    onError={() => {
                        showError(t`PayPal payment failed. Please try again or contact the event organizer.`);
                    }}
                />
            </PayPalScriptProvider>
        </div>
    );
};
