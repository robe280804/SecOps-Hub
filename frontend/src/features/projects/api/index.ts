import { http } from '../../../shared/api/http'
import { createProjectApi } from './projectApi'

export const projectApi = createProjectApi(http)
