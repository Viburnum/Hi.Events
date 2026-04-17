import {Attendee, GenericModalProps, IdParam, Order} from "../../../types.ts";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Badge, Box, Button, Divider, Group, Stack, Table, Text, TextInput} from "@mantine/core";
import {IconBarcode, IconCheck, IconPrinter, IconTruckDelivery} from "@tabler/icons-react";
import {SideDrawer} from "../../common/SideDrawer";
import {useSetAttendeeBarcode} from "../../../mutations/useSetAttendeeBarcode.ts";
import {useUpdateOrderFulfillmentStatus} from "../../../mutations/useUpdateOrderFulfillmentStatus.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {useCallback, useState} from "react";

interface FulfillmentOrderModalProps {
    order: Order;
}

export const FulfillmentOrderModal = ({onClose, order}: GenericModalProps & FulfillmentOrderModalProps) => {
    const {eventId} = useParams();
    const setBarcodeMutation = useSetAttendeeBarcode();
    const markFulfilledMutation = useUpdateOrderFulfillmentStatus();
    const [barcodeValues, setBarcodeValues] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        order.attendees?.forEach((attendee) => {
            if (attendee.id) {
                initial[String(attendee.id)] = '';
            }
        });
        return initial;
    });

    // Track fulfillment status locally so the UI updates after saving barcodes
    const [fulfilledAttendeeIds, setFulfilledAttendeeIds] = useState<Set<number>>(() => {
        const ids = new Set<number>();
        order.attendees?.forEach((a) => {
            if (a.fulfillment_status === 'FULFILLED' && a.id) {
                ids.add(a.id);
            }
        });
        return ids;
    });

    const markAttendeeAsFulfilled = useCallback((attendeeId: number) => {
        setFulfilledAttendeeIds((prev) => new Set(prev).add(attendeeId));
    }, []);

    const attendees = order.attendees || [];
    const allAttendeesHaveBarcodes = attendees.length > 0 && attendees.every(
        (a) => a.id !== undefined && fulfilledAttendeeIds.has(a.id)
    );
    const isFulfilled = order.fulfillment_status === 'FULFILLED';

    const handleBarcodeChange = (attendeeId: IdParam, value: string) => {
        setBarcodeValues((prev) => ({...prev, [String(attendeeId)]: value}));
    };

    const handleSaveBarcode = (attendeeId: IdParam) => {
        const barcode = barcodeValues[String(attendeeId)];
        if (!barcode || !barcode.trim()) {
            showError(t`Please enter a barcode value`);
            return;
        }

        setBarcodeMutation.mutate(
            {eventId, attendeeId, barcode: barcode.trim()},
            {
                onSuccess: () => {
                    showSuccess(t`Barcode saved successfully`);
                    markAttendeeAsFulfilled(Number(attendeeId));
                },
                onError: () => {
                    showError(t`Failed to save barcode. Please try again.`);
                },
            }
        );
    };

    const handleMarkFulfilled = () => {
        markFulfilledMutation.mutate(
            {eventId, orderId: order.id},
            {
                onSuccess: () => {
                    showSuccess(t`Order marked as fulfilled`);
                    onClose();
                },
                onError: () => {
                    showError(t`Failed to mark order as fulfilled. Please try again.`);
                },
            }
        );
    };

    const handlePrintShippingLabel = () => {
        const address = order.address;
        const printWindow = window.open('', '_blank', 'width=400,height=600');
        if (!printWindow) return;

        const addressHtml = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>${t`Shipping Label`}</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        padding: 40px;
                        font-size: 14px;
                    }
                    .label {
                        border: 2px solid #000;
                        padding: 30px;
                        max-width: 400px;
                    }
                    .from, .to { margin-bottom: 20px; }
                    .to { font-size: 16px; font-weight: bold; }
                    .heading { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
                    @media print {
                        body { padding: 0; }
                    }
                </style>
            </head>
            <body>
                <div class="label">
                    <div class="to">
                        <div class="heading">${t`Ship To`}</div>
                        <div>${order.first_name} ${order.last_name}</div>
                        ${order.company_name ? `<div>${order.company_name}</div>` : ''}
                        ${address?.address_line_1 ? `<div>${address.address_line_1}</div>` : ''}
                        ${address?.address_line_2 ? `<div>${address.address_line_2}</div>` : ''}
                        <div>${[address?.city, address?.state_or_region, address?.zip_or_postal_code].filter(Boolean).join(', ')}</div>
                        ${address?.country ? `<div>${address.country}</div>` : ''}
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        ${t`Order`}: ${order.public_id}
                    </div>
                </div>
                <script>window.print();</script>
            </body>
            </html>
        `;

        printWindow.document.write(addressHtml);
        printWindow.document.close();
    };

    const FulfillmentStatusBadge = ({status}: { status?: string | null }) => {
        if (status === 'FULFILLED') {
            return <Badge color="green" variant="light">{t`Fulfilled`}</Badge>;
        }
        return <Badge color="orange" variant="light">{t`Pending`}</Badge>;
    };

    return (
        <SideDrawer
            opened={true}
            onClose={onClose}
            heading={t`Fulfillment - Order #${order.public_id}`}
        >
            <Stack gap="lg">
                {/* Order Info */}
                <Box>
                    <Text fw={600} size="sm" mb="xs">{t`Customer Information`}</Text>
                    <Table>
                        <Table.Tbody>
                            <Table.Tr>
                                <Table.Td fw={500}>{t`Name`}</Table.Td>
                                <Table.Td>{order.first_name} {order.last_name}</Table.Td>
                            </Table.Tr>
                            <Table.Tr>
                                <Table.Td fw={500}>{t`Email`}</Table.Td>
                                <Table.Td>{order.email}</Table.Td>
                            </Table.Tr>
                            {order.company_name && (
                                <Table.Tr>
                                    <Table.Td fw={500}>{t`Company`}</Table.Td>
                                    <Table.Td>{order.company_name}</Table.Td>
                                </Table.Tr>
                            )}
                            <Table.Tr>
                                <Table.Td fw={500}>{t`Status`}</Table.Td>
                                <Table.Td><FulfillmentStatusBadge status={order.fulfillment_status}/></Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </Box>

                {/* Shipping Address */}
                {order.address && (
                    <Box>
                        <Group justify="space-between" mb="xs">
                            <Text fw={600} size="sm">{t`Shipping Address`}</Text>
                            <Button
                                variant="light"
                                size="xs"
                                leftSection={<IconPrinter size={14}/>}
                                onClick={handlePrintShippingLabel}
                            >
                                {t`Print Shipping Label`}
                            </Button>
                        </Group>
                        <Box p="sm" style={{border: '1px solid var(--mantine-color-gray-3)', borderRadius: 8}}>
                            <Text size="sm">{order.first_name} {order.last_name}</Text>
                            {order.company_name && <Text size="sm">{order.company_name}</Text>}
                            {order.address.address_line_1 && <Text size="sm">{order.address.address_line_1}</Text>}
                            {order.address.address_line_2 && <Text size="sm">{order.address.address_line_2}</Text>}
                            <Text size="sm">
                                {[order.address.city, order.address.state_or_region, order.address.zip_or_postal_code].filter(Boolean).join(', ')}
                            </Text>
                            {order.address.country && <Text size="sm">{order.address.country}</Text>}
                        </Box>
                    </Box>
                )}

                <Divider/>

                {/* Attendees / Hard Tickets */}
                <Box>
                    <Text fw={600} size="sm" mb="xs">{t`Hard Ticket Attendees`}</Text>
                    {attendees.length === 0 ? (
                        <Text size="sm" c="dimmed">{t`No attendees found for this order.`}</Text>
                    ) : (
                        <Table striped highlightOnHover>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t`Attendee`}</Table.Th>
                                    <Table.Th>{t`Product`}</Table.Th>
                                    <Table.Th>{t`Barcode`}</Table.Th>
                                    <Table.Th>{t`Status`}</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {attendees.map((attendee: Attendee) => (
                                    <Table.Tr key={attendee.id}>
                                        <Table.Td>
                                            <Text size="sm">{attendee.first_name} {attendee.last_name}</Text>
                                            <Text size="xs" c="dimmed">{attendee.email}</Text>
                                        </Table.Td>
                                        <Table.Td>
                                            <Text size="sm">{attendee.product?.title || '-'}</Text>
                                        </Table.Td>
                                        <Table.Td>
                                            {(attendee.id !== undefined && fulfilledAttendeeIds.has(attendee.id)) ? (
                                                <Group gap="xs">
                                                    <IconCheck size={14} color="green"/>
                                                    <Text size="sm" c="green">{t`Assigned`}</Text>
                                                </Group>
                                            ) : (
                                                <Group gap="xs" wrap="nowrap">
                                                    <TextInput
                                                        size="xs"
                                                        placeholder={t`Enter barcode`}
                                                        value={barcodeValues[String(attendee.id)] || ''}
                                                        onChange={(e) => handleBarcodeChange(attendee.id, e.currentTarget.value)}
                                                        leftSection={<IconBarcode size={14}/>}
                                                        style={{flex: 1, minWidth: 120}}
                                                    />
                                                    <Button
                                                        size="xs"
                                                        variant="light"
                                                        onClick={() => handleSaveBarcode(attendee.id)}
                                                        loading={setBarcodeMutation.isPending}
                                                    >
                                                        {t`Save`}
                                                    </Button>
                                                </Group>
                                            )}
                                        </Table.Td>
                                        <Table.Td>
                                            <FulfillmentStatusBadge status={(attendee.id !== undefined && fulfilledAttendeeIds.has(attendee.id)) ? 'FULFILLED' : attendee.fulfillment_status}/>
                                        </Table.Td>
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    )}
                </Box>

                <Divider/>

                {/* Mark as Fulfilled */}
                <Group justify="flex-end">
                    <Button
                        leftSection={<IconTruckDelivery size={16}/>}
                        color="green"
                        onClick={handleMarkFulfilled}
                        loading={markFulfilledMutation.isPending}
                        disabled={isFulfilled || !allAttendeesHaveBarcodes}
                    >
                        {isFulfilled ? t`Already Fulfilled` : t`Mark as Fulfilled`}
                    </Button>
                </Group>
            </Stack>
        </SideDrawer>
    );
};
