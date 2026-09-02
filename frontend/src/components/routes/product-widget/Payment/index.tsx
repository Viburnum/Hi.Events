import React, {useState} from "react";
import {useNavigate, useParams} from "react-router";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {CheckoutStepTitle} from "../../../layouts/Checkout/CheckoutStepTitle";
import {StripePaymentMethod} from "./PaymentMethods/Stripe";
import {OfflinePaymentMethod} from "./PaymentMethods/Offline";
import {PaypalPaymentMethod} from "./PaymentMethods/Paypal";
import {Event} from "../../../../types.ts";
import {Button, Group, Text} from "@mantine/core";
import {IconBrandPaypal, IconBuildingBank, IconLock, IconWallet} from "@tabler/icons-react";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {t, Trans} from "@lingui/macro";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {
    useTransitionOrderToOfflinePaymentPublic
} from "../../../../mutations/useTransitionOrderToOfflinePaymentPublic.ts";
import {Card} from "../../../common/Card";
import {InlineOrderSummary} from "../../../common/InlineOrderSummary";
import {showError} from "../../../../utilites/notifications.tsx";
import {getConfig} from "../../../../utilites/config.ts";
import classes from "./Payment.module.scss";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";

const Payment = () => {
    const navigate = useNavigate();
    const {eventId, orderShortId} = useParams();
    const {data: event, isFetched: isEventFetched} = useGetEventPublic(eventId);
    const {data: order, isFetched: isOrderFetched} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const isLoading = !isOrderFetched;
    const checkoutEvent = order?.event || event;
    const [isPaymentLoading, setIsPaymentLoading] = useState(false);
    const [activePaymentMethod, setActivePaymentMethod] = useState<'STRIPE' | 'OFFLINE' | 'PAYPAL' | null>(null);
    const [submitHandler, setSubmitHandler] = useState<(() => Promise<void>) | null>(null);
    const transitionOrderToOfflinePaymentMutation = useTransitionOrderToOfflinePaymentPublic();

    const isStripeEnabled = event?.settings?.payment_providers?.includes('STRIPE');
    const isOfflineEnabled = event?.settings?.payment_providers?.includes('OFFLINE');
    const isPaypalEnabled = event?.settings?.payment_providers?.includes('PAYPAL');
    const enabledProviderCount = [isStripeEnabled, isOfflineEnabled, isPaypalEnabled].filter(Boolean).length;
    const hasAnyPaymentMethod = isStripeEnabled || isOfflineEnabled || isPaypalEnabled;

    React.useEffect(() => {
        // Automatically set the first available payment method
        if (isStripeEnabled) {
            setActivePaymentMethod('STRIPE');
        } else if (isPaypalEnabled) {
            setActivePaymentMethod('PAYPAL');
        } else if (isOfflineEnabled) {
            setActivePaymentMethod('OFFLINE');
        } else {
            setActivePaymentMethod(null); // No methods available
        }
    }, [isStripeEnabled, isOfflineEnabled, isPaypalEnabled]);

    React.useEffect(() => {
        // Scroll to top when payment page loads
        window?.scrollTo(0, 0);
    }, []);

    const handleParentSubmit = () => {
        if (submitHandler) {
            setIsPaymentLoading(true);
            submitHandler().finally(() => setIsPaymentLoading(false));
        }
    };

    const handleSubmit = async () => {
        if (activePaymentMethod === 'STRIPE') {
            handleParentSubmit();
        } else if (activePaymentMethod === 'PAYPAL') {
            // PayPal renders its own buttons which drive submission, so this is a no-op.
        } else if (activePaymentMethod === 'OFFLINE') {
            setIsPaymentLoading(true);

            await transitionOrderToOfflinePaymentMutation.mutateAsync({
                eventId,
                orderShortId
            }, {
                onSuccess: () => {
                    const totalCents = Math.round((order?.total_gross || 0) * 100);
                    trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_OFFLINE, { value: totalCents });
                    navigate(`/checkout/${eventId}/${orderShortId}/summary`);
                },
                onError: (error: any) => {
                    setIsPaymentLoading(false);
                    showError(error.response?.data?.message || t`Offline payment failed. Please try again or contact the event organizer.`);
                }
            });
        }
    };

    if (!hasAnyPaymentMethod && isOrderFetched && isEventFetched) {
        return (
            <CheckoutContent>
                <Card>
                    {t`No payment methods are currently available. Please contact the event organizer for assistance.`}
                </Card>
            </CheckoutContent>
        );
    }

    return (
        <>
            <CheckoutContent>
                <CheckoutStepTitle title={t`Payment`} subtitle={t`Next: review your order`}/>

                {(checkoutEvent && order) && (
                    <InlineOrderSummary event={checkoutEvent} order={order} defaultExpanded={false}/>
                )}
                {isStripeEnabled && (
                    <div style={{display: activePaymentMethod === 'STRIPE' ? 'block' : 'none'}}>
                        <StripePaymentMethod enabled={true} setSubmitHandler={setSubmitHandler}/>
                    </div>
                )}

                {isPaypalEnabled && (
                    <div style={{display: activePaymentMethod === 'PAYPAL' ? 'block' : 'none'}}>
                        <PaypalPaymentMethod enabled={activePaymentMethod === 'PAYPAL'}/>
                    </div>
                )}

                {isOfflineEnabled && (
                    <div style={{display: activePaymentMethod === 'OFFLINE' ? 'block' : 'none'}}>
                        <OfflinePaymentMethod event={checkoutEvent as Event}/>
                    </div>
                )}

                {enabledProviderCount > 1 && (
                    <div className={classes.paymentMethodSelector}>
                        <Text size="sm" c="dimmed" className={classes.paymentMethodLabel}>
                            {t`Payment method`}
                        </Text>
                        <div className={classes.paymentMethodTabs}>
                            {isStripeEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'STRIPE' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('STRIPE')}
                                >
                                    <IconWallet size={18}/>
                                    <span>{t`Online`}</span>
                                </button>
                            )}
                            {isPaypalEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'PAYPAL' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('PAYPAL')}
                                >
                                    <IconBrandPaypal size={18}/>
                                    <span>{t`PayPal`}</span>
                                </button>
                            )}
                            {isOfflineEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'OFFLINE' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('OFFLINE')}
                                >
                                    <IconBuildingBank size={18}/>
                                    <span>{t`Offline`}</span>
                                </button>
                            )}
                        </div>
                    </div>
                )}

                <div className={classes.checkoutActions}>
                    {activePaymentMethod !== 'PAYPAL' && (
                        <Button
                            className={classes.continueButton}
                            loading={isLoading || isPaymentLoading}
                            onClick={handleSubmit}
                            data-testid={activePaymentMethod === 'OFFLINE' ? 'offline-payment-button' : undefined}
                        >
                            {order?.is_payment_required ? (
                                <Group gap={8} wrap="nowrap">
                                    <IconLock size={16}/>
                                    <Text fw={600}>{t`Pay`} {formatCurrency(order.total_gross, order.currency)}</Text>
                                </Group>
                            ) : t`Complete Payment`}
                        </Button>
                    )}
                    {getConfig('VITE_TOS_URL') && (
                        <p className={classes.tosNotice}>
                            <Trans>
                                By continuing, you agree to the{' '}
                                <a
                                    href={getConfig('VITE_TOS_URL', 'https://hi.events/terms-of-service') as string}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {getConfig('VITE_APP_NAME', 'Hi.Events')} Terms of Service
                                </a>
                            </Trans>
                        </p>
                    )}
                </div>
            </CheckoutContent>
        </>
    );
}

export default Payment;
