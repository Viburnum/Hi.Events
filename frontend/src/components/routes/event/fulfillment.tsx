import React, {useMemo, useState} from "react";
import {useParams} from "react-router";
import {Anchor, Badge, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent";
import {useGetFulfillmentOrders} from "../../../queries/useGetFulfillmentOrders";
import {PageTitle} from "../../common/PageTitle";
import {PageBody} from "../../common/PageBody";
import {SearchBarWrapper} from "../../common/SearchBar";
import {Pagination} from "../../common/Pagination";
import {ToolBar} from "../../common/ToolBar";
import {useFilterQueryParamSync} from "../../../hooks/useFilterQueryParamSync";
import {Order, QueryFilterOperator, QueryFilters} from "../../../types";
import {TableSkeleton} from "../../common/TableSkeleton";
import {FilterModal, FilterOption} from "../../common/FilterModal";
import {NoResultsSplash} from "../../common/NoResultsSplash";
import {TanStackTable, TanStackTableColumn} from "../../common/TanStackTable";
import {FulfillmentOrderModal} from "../../modals/FulfillmentOrderModal";
import {useDisclosure} from "@mantine/hooks";
import {relativeDate} from "../../../utilites/dates.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {IconCheck, IconClock} from "@tabler/icons-react";
import {CellContext} from "@tanstack/react-table";

const fulfillmentStatuses = [
    {label: t`Pending`, value: 'PENDING'},
    {label: t`Fulfilled`, value: 'FULFILLED'},
];

export const Fulfillment: React.FC = () => {
    const {eventId} = useParams<{ eventId: string }>();
    const {data: event} = useGetEvent(eventId);
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const ordersQuery = useGetFulfillmentOrders(eventId, searchParams as QueryFilters);
    const orders = ordersQuery?.data?.data;
    const pagination = ordersQuery?.data?.meta;
    const [isModalOpen, modal] = useDisclosure(false);
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);

    const filterOptions: FilterOption[] = [
        {
            field: 'fulfillment_status',
            label: t`Fulfillment Status`,
            type: 'multi-select',
            options: fulfillmentStatuses
        },
    ];

    const handleFilterChange = (values: Record<string, string[]>) => {
        const newFilters = {
            ...searchParams,
            filterFields: {
                ...(searchParams.filterFields || {}),
                fulfillment_status: values.fulfillment_status?.length > 0
                    ? {operator: QueryFilterOperator.In, value: values.fulfillment_status}
                    : undefined,
            }
        };

        setSearchParams(newFilters as QueryFilters, true);
    };

    const handleResetFilters = () => {
        const clearedFilters = {
            ...searchParams,
            filterFields: {}
        };
        setSearchParams(clearedFilters as QueryFilters, true);
    };

    const handleOrderClick = (order: Order) => {
        setSelectedOrder(order);
        modal.open();
    };

    const fulfillmentFilter = searchParams.filterFields?.fulfillment_status;
    const currentFilters = {
        fulfillment_status: (fulfillmentFilter && !Array.isArray(fulfillmentFilter) ? fulfillmentFilter.value : []) as string[],
    };

    const FulfillmentStatusBadge = ({status}: { status?: string | null }) => {
        if (status === 'FULFILLED') {
            return (
                <Badge color="green" variant="light" leftSection={<IconCheck size={12}/>}>
                    {t`Fulfilled`}
                </Badge>
            );
        }
        return (
            <Badge color="orange" variant="light" leftSection={<IconClock size={12}/>}>
                {t`Pending`}
            </Badge>
        );
    };

    const columns = useMemo<TanStackTableColumn<Order>[]>(
        () => [
            {
                id: 'customer',
                header: t`Customer`,
                enableHiding: false,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    return (
                        <div>
                            <Anchor
                                onClick={() => handleOrderClick(order)}
                                style={{cursor: 'pointer', fontWeight: 500}}
                            >
                                {order.first_name} {order.last_name}
                            </Anchor>
                            <Text size="xs" c="dimmed">{order.email}</Text>
                        </div>
                    );
                },
                meta: {
                    headerStyle: {minWidth: 220},
                },
            },
            {
                id: 'orderId',
                header: t`Order ID`,
                enableHiding: true,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    return (
                        <Anchor
                            onClick={() => handleOrderClick(order)}
                            style={{cursor: 'pointer'}}
                            size="sm"
                        >
                            {order.public_id}
                        </Anchor>
                    );
                },
            },
            {
                id: 'date',
                header: t`Date`,
                enableHiding: true,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    return <Text size="sm">{relativeDate(order.created_at)}</Text>;
                },
            },
            {
                id: 'amount',
                header: t`Total`,
                enableHiding: true,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    return (
                        <Text size="sm" fw={500}>
                            {formatCurrency(order.total_gross, order.currency)}
                        </Text>
                    );
                },
            },
            {
                id: 'attendees',
                header: t`Attendees`,
                enableHiding: true,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    const total = order.attendees?.length || 0;
                    const fulfilled = order.attendees?.filter(a => a.fulfillment_status === 'FULFILLED').length || 0;
                    return (
                        <Text size="sm">
                            {fulfilled}/{total} {t`assigned`}
                        </Text>
                    );
                },
            },
            {
                id: 'fulfillmentStatus',
                header: t`Fulfillment Status`,
                enableHiding: true,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    return <FulfillmentStatusBadge status={order.fulfillment_status}/>;
                },
            },
            {
                id: 'actions',
                header: t`Actions`,
                enableHiding: false,
                cell: (info: CellContext<Order, unknown>) => {
                    const order = info.row.original;
                    return (
                        <Anchor
                            onClick={() => handleOrderClick(order)}
                            style={{cursor: 'pointer'}}
                            size="sm"
                        >
                            {t`Manage`}
                        </Anchor>
                    );
                },
            },
        ],
        []
    );

    return (
        <PageBody>
            <PageTitle
                subheading={t`Manage hard ticket fulfillment, assign barcodes, and print shipping labels.`}
            >{t`Fulfillment`}</PageTitle>
            <ToolBar
                filterComponent={
                    <FilterModal
                        filters={filterOptions}
                        activeFilters={currentFilters}
                        onChange={handleFilterChange}
                        onReset={handleResetFilters}
                        title={t`Filter Orders`}
                    />
                }
                searchComponent={() => (
                    <SearchBarWrapper
                        placeholder={t`Search by name, email, or order #...`}
                        setSearchParams={setSearchParams}
                        searchParams={searchParams}
                    />
                )}
            />

            <TableSkeleton isVisible={!orders || ordersQuery.isFetching}/>

            {orders && orders.length === 0 && !ordersQuery.isFetching && (
                <NoResultsSplash
                    imageHref={'/blank-slate/orders.svg'}
                    heading={t`No fulfillment orders to show`}
                    subHeading={(
                        <p>
                            {t`Orders requiring hard ticket fulfillment will appear here.`}
                        </p>
                    )}
                />
            )}

            {orders && orders.length > 0 && event && (
                <TanStackTable
                    data={orders}
                    columns={columns}
                    storageKey="fulfillment-orders-table"
                    enableColumnVisibility={true}
                />
            )}

            {!!orders?.length && (
                <Pagination
                    value={searchParams.pageNumber}
                    onChange={(value) => setSearchParams({pageNumber: value})}
                    total={Number(pagination?.last_page)}
                />
            )}

            {selectedOrder && isModalOpen && (
                <FulfillmentOrderModal
                    onClose={() => {
                        modal.close();
                        ordersQuery.refetch();
                    }}
                    order={selectedOrder}
                />
            )}
        </PageBody>
    );
};

export default Fulfillment;
