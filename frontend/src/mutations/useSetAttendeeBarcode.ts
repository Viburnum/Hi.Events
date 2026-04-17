import {useMutation} from "@tanstack/react-query";
import {fulfillmentClient} from "../api/fulfillment.client.ts";
import {IdParam} from "../types.ts";
import {GET_FULFILLMENT_ORDERS_QUERY_KEY} from "../queries/useGetFulfillmentOrders.ts";
import {queryClient} from "../utilites/queryClient.ts";

export const useSetAttendeeBarcode = () => {
    return useMutation({
        mutationFn: ({eventId, attendeeId, barcode}: {
            eventId: IdParam,
            attendeeId: IdParam,
            barcode: string,
        }) => {
            return fulfillmentClient.setAttendeeBarcode(eventId, attendeeId, barcode);
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
