import {Modal} from "../../common/Modal";
import {Address, GenericModalProps, IdParam, Product, ProductType} from "../../../types.ts";
import {Button} from "../../common/Button";
import {useNavigate, useParams} from "react-router";
import {useForm} from "@mantine/form";
import {ActionIcon, LoadingOverlay, NativeSelect, NumberInput, Select, Switch, Text, TextInput} from "@mantine/core";
import {IconPlus, IconTrash} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useCreateManualOrder} from "../../../mutations/useCreateManualOrder.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {getProductsFromEvent} from "../../../utilites/helpers.ts";
import {InputGroup} from "../../common/InputGroup";
import {
    getClientLocale,
    getLocaleName,
    localeToFlagEmojiMap,
    localeToNameMap,
    SupportedLocales
} from "../../../locales.ts";
import {CreateManualOrderPayload, ManualOrderAttendee} from "../../../api/order.client.ts";
import countries from "../../../../data/countries.json";
import classes from "./CreateManualOrderModal.module.scss";

interface FormValues extends Omit<CreateManualOrderPayload, 'address'> {
    include_address: boolean;
    address: Address;
}

const emptyAddress: Address = {
    address_line_1: '',
    address_line_2: '',
    city: '',
    state_or_region: '',
    zip_or_postal_code: '',
    country: '',
};

const emptyAttendee = (): ManualOrderAttendee => ({
    product_id: undefined,
    product_price_id: undefined,
    first_name: '',
    last_name: '',
    email: '',
    amount_paid: 0,
    taxes_and_fees: [],
});

const ticketOptionValue = (productId?: IdParam, priceId?: IdParam) => `${productId}:${priceId}`;

export const CreateManualOrderModal = ({onClose}: GenericModalProps) => {
    const {eventId} = useParams();
    const navigate = useNavigate();
    const errorHandler = useFormErrorResponseHandler();
    const {data: event, isFetched: isEventFetched} = useGetEvent(eventId);
    const mutation = useCreateManualOrder();

    const ticketProducts = (getProductsFromEvent(event) || [])
        .filter((product) => product.product_type === ProductType.Ticket);

    const ticketOptions = ticketProducts.flatMap((product) => (product.prices || []).map((price) => ({
        value: ticketOptionValue(product.id, price.id),
        label: product.type === 'TIERED' && price.label
            ? `${product.title} – ${price.label}`
            : product.title,
    })));

    const form = useForm<FormValues>({
        initialValues: {
            first_name: '',
            last_name: '',
            email: '',
            locale: getClientLocale() as SupportedLocales,
            send_confirmation_email: true,
            include_address: false,
            address: {...emptyAddress},
            attendees: [emptyAttendee()],
        },
    });

    const findProduct = (productId?: IdParam): Product | undefined =>
        ticketProducts.find((product) => product.id == productId);

    const hasHardTicket = form.values.attendees.some((attendee) => findProduct(attendee.product_id)?.is_hard_ticket);

    const handleTicketChange = (index: number, value: string | null) => {
        const [productId, priceId] = (value || '').split(':').map(Number);
        const product = findProduct(productId);
        const price = product?.prices?.find((p) => p.id === priceId);

        form.setFieldValue(`attendees.${index}`, {
            ...form.values.attendees[index],
            product_id: product?.id,
            product_price_id: price?.id,
            amount_paid: Number(price?.price ?? 0),
            taxes_and_fees: (product?.taxes_and_fees || []).map((tax) => ({
                tax_or_fee_id: tax.id as IdParam,
                amount: 0,
                name: tax.name,
            })),
        });

        if (product?.is_hard_ticket) {
            form.setFieldValue('include_address', true);
        }
    };

    const copyBuyerToAttendee = (index: number) => {
        form.setFieldValue(`attendees.${index}.first_name`, form.values.first_name);
        form.setFieldValue(`attendees.${index}.last_name`, form.values.last_name);
        form.setFieldValue(`attendees.${index}.email`, form.values.email);
    };

    const handleSubmit = (values: FormValues) => {
        const {include_address, address, ...rest} = values;

        mutation.mutate({
            eventId,
            payload: {
                ...rest,
                address: include_address ? address : null,
                attendees: rest.attendees.map(({taxes_and_fees, ...attendee}) => ({
                    ...attendee,
                    taxes_and_fees: taxes_and_fees.map(({tax_or_fee_id, amount}) => ({tax_or_fee_id, amount})),
                })),
            },
        }, {
            onSuccess: () => {
                showSuccess(t`Order created successfully`);
                onClose();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    if (!event?.product_categories) {
        return <LoadingOverlay visible/>;
    }

    if (isEventFetched && ticketOptions.length === 0) {
        return (
            <Modal opened onClose={onClose} heading={t`Create Order`}>
                <p>{t`You must create a ticket before you can manually create an order.`}</p>
                <Button fullWidth variant={'light'} onClick={() => navigate(`/manage/event/${eventId}/products`)}>
                    {t`Manage tickets`}
                </Button>
            </Modal>
        );
    }

    return (
        <Modal opened onClose={onClose} heading={t`Create Order`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <h3 className={classes.sectionHeading}>{t`Buyer`}</h3>
                <InputGroup>
                    <TextInput {...form.getInputProps('first_name')} label={t`First name`} required/>
                    <TextInput {...form.getInputProps('last_name')} label={t`Last name`}/>
                </InputGroup>
                <TextInput {...form.getInputProps('email')} label={t`Email address`} type="email" required/>
                <Select
                    required
                    {...form.getInputProps('locale')}
                    data={Object.keys(localeToNameMap).map((locale) => ({
                        value: locale,
                        label: localeToFlagEmojiMap[locale as SupportedLocales] + ' ' + getLocaleName(locale as SupportedLocales),
                    }))}
                    label={t`Language`}
                    description={t`The language the buyer and attendees will receive emails in.`}
                />

                <Switch
                    mt="md"
                    label={t`Add shipping address`}
                    description={hasHardTicket ? t`This order contains hard tickets that need to be shipped.` : undefined}
                    {...form.getInputProps('include_address', {type: 'checkbox'})}
                />

                {form.values.include_address && (
                    <div className={classes.addressFields}>
                        <InputGroup>
                            <TextInput {...form.getInputProps('address.address_line_1')} label={t`Address Line 1`} required/>
                            <TextInput {...form.getInputProps('address.address_line_2')} label={t`Address Line 2`}/>
                        </InputGroup>
                        <InputGroup>
                            <TextInput {...form.getInputProps('address.zip_or_postal_code')} label={t`ZIP / Postal Code`}/>
                            <TextInput {...form.getInputProps('address.city')} label={t`City`} required/>
                        </InputGroup>
                        <InputGroup>
                            <TextInput {...form.getInputProps('address.state_or_region')} label={t`State or Region`}/>
                            <NativeSelect {...form.getInputProps('address.country')} label={t`Country`} data={countries} required/>
                        </InputGroup>
                    </div>
                )}

                <h3 className={classes.sectionHeading}>{t`Attendees`}</h3>
                {form.errors.attendees && <Text c="red" size="sm">{form.errors.attendees}</Text>}

                {form.values.attendees.map((attendee, index) => (
                    <div className={classes.attendeeCard} key={index}>
                        <div className={classes.attendeeHeader}>
                            <Text fw={600} size="sm">{t`Attendee ${index + 1}`}</Text>
                            <div className={classes.attendeeActions}>
                                <Button variant="subtle" size="xs" onClick={() => copyBuyerToAttendee(index)}>
                                    {t`Use buyer details`}
                                </Button>
                                {form.values.attendees.length > 1 && (
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        aria-label={t`Remove attendee`}
                                        onClick={() => form.removeListItem('attendees', index)}
                                    >
                                        <IconTrash size={16}/>
                                    </ActionIcon>
                                )}
                            </div>
                        </div>

                        <Select
                            required
                            searchable
                            label={t`Ticket`}
                            placeholder={t`Select Ticket`}
                            data={ticketOptions}
                            value={attendee.product_id ? ticketOptionValue(attendee.product_id, attendee.product_price_id) : null}
                            onChange={(value) => handleTicketChange(index, value)}
                            error={form.errors[`attendees.${index}.product_id`] || form.errors[`attendees.${index}.product_price_id`]}
                        />

                        <InputGroup>
                            <TextInput {...form.getInputProps(`attendees.${index}.first_name`)} label={t`First name`} required/>
                            <TextInput {...form.getInputProps(`attendees.${index}.last_name`)} label={t`Last name`}/>
                        </InputGroup>
                        <TextInput
                            {...form.getInputProps(`attendees.${index}.email`)}
                            type="email"
                            label={t`Email address`}
                            placeholder={t`Leave empty to use the buyer's email`}
                        />

                        <InputGroup>
                            <NumberInput
                                required
                                fixedDecimalScale
                                decimalScale={2}
                                min={0}
                                {...form.getInputProps(`attendees.${index}.amount_paid`)}
                                label={<Trans>Amount paid ({event.currency})</Trans>}
                                description={t`Excluding taxes and fees.`}
                            />
                            {attendee.taxes_and_fees.map((tax, taxIndex) => (
                                <NumberInput
                                    key={String(tax.tax_or_fee_id)}
                                    required
                                    fixedDecimalScale
                                    decimalScale={2}
                                    min={0}
                                    {...form.getInputProps(`attendees.${index}.taxes_and_fees.${taxIndex}.amount`)}
                                    label={`${tax.name} ${t`paid`} (${event.currency})`}
                                />
                            ))}
                        </InputGroup>
                        {form.errors[`attendees.${index}.taxes_and_fees`] && (
                            <Text c="red" size="sm">{form.errors[`attendees.${index}.taxes_and_fees`]}</Text>
                        )}
                    </div>
                ))}

                <Button
                    variant="light"
                    leftSection={<IconPlus size={16}/>}
                    onClick={() => form.insertListItem('attendees', emptyAttendee())}
                    disabled={form.values.attendees.length >= 100}
                >
                    {t`Add attendee`}
                </Button>

                <Switch
                    mt="xl"
                    label={t`Send order confirmation and ticket email`}
                    {...form.getInputProps('send_confirmation_email', {type: 'checkbox'})}
                />

                <Button type="submit" fullWidth mt="xl" loading={mutation.isPending}>
                    {t`Create order with ${form.values.attendees.length} attendee(s)`}
                </Button>
            </form>
        </Modal>
    );
};
