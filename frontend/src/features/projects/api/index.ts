import { http } from '../../../shared/api/http'
import { createProjectApi } from './projectApi'
import { createMembershipApi } from './membershipApi'
import { createEnvironmentApi } from './environmentApi'

export const projectApi = createProjectApi(http)
export const membershipApi = createMembershipApi(http)
export const environmentApi = createEnvironmentApi(http)
