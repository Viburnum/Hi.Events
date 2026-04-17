import {api} from "./client.ts";
import {GenericDataResponse, GenericPaginatedResponse, IdParam, Order, QueryFilters} from "../types.ts";
import {queryParamsHelper} from "../utilites/queryParamsHelper.ts";

export const fulfillmentClient = {
    getOrders: async (eventId: IdParam, pagination: QueryFilters) => {
        const response = await api.get<GenericPaginatedResponse<Order>>(
            `events/${eventId}/fulfillment/orders` + queryParamsHelper.buildQueryString(pagination),
        );
        return response.data;
    },

    setAttendeeBarcode: async (eventId: IdParam, attendeeId: IdParam, barcode: string) => {
        const response = await api.put<GenericDataResponse<Order>>(
            `events/${eventId}/fulfillment/attendees/${attendeeId}/barcode`,
            {barcode},
        );
        return response.data;
    },

    updateOrderFulfillmentStatus: async (eventId: IdParam, orderId: IdParam) => {
        const response = await api.put<GenericDataResponse<Order>>(
            `events/${eventId}/fulfillment/orders/${orderId}/status`,
        );
        return response.data;
    },
};
