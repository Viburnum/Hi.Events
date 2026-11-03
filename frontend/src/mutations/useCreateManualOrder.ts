import {useMutation, useQueryClient} from "@tanstack/react-query";
import {CreateManualOrderPayload, orderClient} from "../api/order.client.ts";
import {IdParam} from "../types.ts";
import {GET_EVENT_ORDERS_QUERY_KEY} from "../queries/useGetEventOrders.ts";
import {GET_ATTENDEES_QUERY_KEY} from "../queries/useGetAttendees.ts";
import {GET_FULFILLMENT_ORDERS_QUERY_KEY} from "../queries/useGetFulfillmentOrders.ts";

export const useCreateManualOrder = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, payload}: {
            eventId: IdParam,
            payload: CreateManualOrderPayload,
        }) => orderClient.createManual(eventId, payload),

        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_EVENT_ORDERS_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_ATTENDEES_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_FULFILLMENT_ORDERS_QUERY_KEY]});
        }
    });
}
