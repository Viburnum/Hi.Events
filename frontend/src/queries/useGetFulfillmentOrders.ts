import {useQuery} from "@tanstack/react-query";
import {fulfillmentClient} from "../api/fulfillment.client.ts";
import {GenericPaginatedResponse, IdParam, Order, QueryFilters} from "../types.ts";

export const GET_FULFILLMENT_ORDERS_QUERY_KEY = 'getFulfillmentOrders';

export const useGetFulfillmentOrders = (eventId: IdParam, pagination: QueryFilters) => {
    return useQuery<GenericPaginatedResponse<Order>>({
            queryKey: [GET_FULFILLMENT_ORDERS_QUERY_KEY, eventId, pagination],
            queryFn: async () => await fulfillmentClient.getOrders(eventId, pagination),
        }
    );
};
