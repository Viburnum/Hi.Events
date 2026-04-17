import {useMutation} from "@tanstack/react-query";
import {fulfillmentClient} from "../api/fulfillment.client.ts";
import {IdParam} from "../types.ts";
import {GET_FULFILLMENT_ORDERS_QUERY_KEY} from "../queries/useGetFulfillmentOrders.ts";
import {queryClient} from "../utilites/queryClient.ts";

export const useUpdateOrderFulfillmentStatus = () => {
    return useMutation({
        mutationFn: ({eventId, orderId}: {
            eventId: IdParam,
            orderId: IdParam,
        }) => {
            return fulfillmentClient.updateOrderFulfillmentStatus(eventId, orderId);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [
                    GET_FULFILLMENT_ORDERS_QUERY_KEY,
                ]
            });
        }
    });
};
