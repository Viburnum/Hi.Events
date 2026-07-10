import {Attendee, GenericModalProps, IdParam, Order} from "../../../types.ts";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Badge, Box, Button, Divider, Group, Stack, Table, Text, TextInput} from "@mantine/core";
import {IconBarcode, IconCheck, IconFileText, IconPrinter, IconTruckDelivery} from "@tabler/icons-react";
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

    const handlePrintDeliverySlip = () => {
        const address = order.address;
        const printWindow = window.open('', '_blank', 'width=800,height=1000');
        if (!printWindow) return;

        const escapeHtml = (value?: string | null) =>
            (value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            }[char] as string));

        const recipientLines = [
            `${escapeHtml(order.first_name)} ${escapeHtml(order.last_name)}`,
            escapeHtml(order.company_name),
            escapeHtml(address?.address_line_1),
            escapeHtml(address?.address_line_2),
            escapeHtml([address?.zip_or_postal_code, address?.city].filter(Boolean).join(' ')),
            escapeHtml([address?.state_or_region, address?.country].filter(Boolean).join(', ')),
        ].filter((line) => line.trim().length > 0);

        const itemRows = attendees.map((attendee) => `
            <tr>
                <td>${escapeHtml(`${attendee.first_name} ${attendee.last_name}`)}</td>
                <td>${escapeHtml(attendee.product?.title || '-')}</td>
                <td>${escapeHtml(attendee.public_id || '-')}</td>
            </tr>
        `).join('');

        const slipHtml = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>${t`Delivery Slip`}</title>
                <style>
                    @page {
                        size: A4;
                        margin: 0;
                    }
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        font-family: Arial, sans-serif;
                        color: #000;
                    }
                    .sheet {
                        position: relative;
                        width: 210mm;
                        height: 297mm;
                        padding: 20mm 20mm 15mm 25mm;
                    }
                    .fold-mark {
                        position: absolute;
                        background: #000;
                    }
                    .fold-mark.horizontal {
                        left: 0;
                        top: 148.5mm;
                        width: 6mm;
                        height: 0.3mm;
                    }
                    .fold-mark.vertical {
                        top: 0;
                        left: 105mm;
                        width: 0.3mm;
                        height: 6mm;
                    }
                    .fold-mark.vertical.bottom {
                        top: auto;
                        bottom: 0;
                    }
                    .sender {
                        position: absolute;
                        left: 25mm;
                        top: 40mm;
                        font-size: 8pt;
                        color: #666;
                        border-bottom: 0.2mm solid #999;
                        padding-bottom: 1mm;
                    }
                    .recipient {
                        position: absolute;
                        left: 25mm;
                        top: 47mm;
                        width: 85mm;
                        min-height: 40mm;
                        font-size: 12pt;
                        line-height: 1.4;
                    }
                    .body {
                        position: absolute;
                        left: 25mm;
                        right: 20mm;
                        top: 120mm;
                    }
                    .body h1 {
                        font-size: 18pt;
                        margin: 0 0 4mm 0;
                    }
                    .meta {
                        font-size: 10pt;
                        color: #444;
                        margin-bottom: 8mm;
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        font-size: 10pt;
                    }
                    th, td {
                        text-align: left;
                        padding: 2mm 3mm;
                        border-bottom: 0.2mm solid #ccc;
                    }
                    th {
                        text-transform: uppercase;
                        font-size: 8pt;
                        color: #666;
                    }
                    .footer {
                        margin-top: 10mm;
                        font-size: 9pt;
                        color: #666;
                    }
                </style>
            </head>
            <body>
                <div class="sheet">
                    <div class="fold-mark horizontal"></div>
                    <div class="fold-mark vertical"></div>
                    <div class="fold-mark vertical bottom"></div>

                    <div class="sender">${t`Delivery Slip`} · ${escapeHtml(order.public_id)}</div>
                    <div class="recipient">
                        ${recipientLines.map((line) => `<div>${line}</div>`).join('')}
                    </div>

                    <div class="body">
                        <h1>${t`Delivery Slip`}</h1>
                        <div class="meta">${t`Order`}: ${escapeHtml(order.public_id)}</div>
                        <table>
                            <thead>
                                <tr>
                                    <th>${t`Attendee`}</th>
                                    <th>${t`Product`}</th>
                                    <th>${t`Barcode`}</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemRows || `<tr><td colspan="3">${t`No items`}</td></tr>`}
                            </tbody>
                        </table>
                        <div class="footer">${t`Please fold along the marks to fit a DIN C6 window envelope.`}</div>
                    </div>
                </div>
                <script>window.print();</script>
            </body>
            </html>
        `;

        printWindow.document.write(slipHtml);
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
                            <Group gap="xs">
                                <Button
                                    variant="light"
                                    size="xs"
                                    leftSection={<IconPrinter size={14}/>}
                                    onClick={handlePrintShippingLabel}
                                >
                                    {t`Print Shipping Label`}
                                </Button>
                                <Button
                                    variant="light"
                                    size="xs"
                                    leftSection={<IconFileText size={14}/>}
                                    onClick={handlePrintDeliverySlip}
                                >
                                    {t`Print Delivery Slip`}
                                </Button>
                            </Group>
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
