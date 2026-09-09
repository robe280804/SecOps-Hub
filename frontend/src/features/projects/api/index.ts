import { http } from '../../../shared/api/http'
import { createProjectApi } from './projectApi'
import { createMembershipApi } from './membershipApi'

export const projectApi = createProjectApi(http)
export const membershipApi = createMembershipApi(http)
