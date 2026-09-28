import { isAxiosError } from 'axios'
import { useQuery } from '@tanstack/react-query'
import { fetchRoomPlan } from '../api/floorPlanApi'

export const floorPlanKeys = {
  all: ['floor-plan'] as const,
  room: (roomId: string) => ['floor-plan', 'room', roomId] as const,
}

export function useRoomPlan(roomId: string | undefined) {
  return useQuery({
    queryKey: floorPlanKeys.room(roomId ?? ''),
    queryFn: () => fetchRoomPlan(roomId as string),
    enabled: Boolean(roomId),
    // A refusal or a missing room will not change on a second try; retrying
    // only delays the Forbidden / not-found screen.
    retry: (count, error) =>
      !(isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) && count < 1,
  })
}
