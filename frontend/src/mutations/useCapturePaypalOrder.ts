import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";
import {useMutation} from "@tanstack/react-query";

export const useCapturePaypalOrder = () => {
    return useMutation({
        mutationFn: ({eventId, orderShortId, paypalOrderId}: {
            eventId: IdParam,
            orderShortId: IdParam,
            paypalOrderId: string,
        }) => {
            return orderClientPublic.capturePaypalOrder(
                Number(eventId),
                String(orderShortId),
                paypalOrderId,
            );
        }
    });
}
