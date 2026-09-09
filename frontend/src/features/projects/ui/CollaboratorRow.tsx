import { useState } from 'react'
import { accessLevelSchema, type Membership, type AccessLevel } from '../model/membershipSchema'

type Props = {
  membership: Membership
  archived: boolean
  disabled: boolean
  onUpdate: (id: number, accessLevel: AccessLevel) => Promise<boolean>
  onRemove: (id: number) => Promise<boolean>
}

export function CollaboratorRow({ membership, archived, disabled, onUpdate, onRemove }: Props) {
  const [accessLevel, setAccessLevel] = useState(membership.access_level)
  const [confirming, setConfirming] = useState(false)
  return <li className="grid gap-3 rounded border bg-white p-4">
    <p className="break-words"><strong>{membership.user.name}</strong> — {membership.user.email}</p>
    <div className="flex flex-wrap items-center gap-3">
      {archived ? <p>Role: {membership.access_level}</p> : <>
        <label htmlFor={'membership-role-' + membership.id}>Role</label>
        <select id={'membership-role-' + membership.id} disabled={disabled || confirming} value={accessLevel}
          onChange={(event) => setAccessLevel(accessLevelSchema.parse(event.target.value))} className="rounded border p-2">
          <option value="viewer">Viewer</option><option value="contributor">Contributor</option>
        </select>
        <button disabled={disabled || confirming || accessLevel === membership.access_level}
          onClick={() => onUpdate(membership.id, accessLevel)} className="rounded border px-3 py-2 disabled:opacity-40">Save role</button>
      </>}
      {!confirming && <button disabled={disabled} onClick={() => setConfirming(true)} className="rounded border px-3 py-2 disabled:opacity-40">Remove</button>}
    </div>
    {confirming && <div className="grid gap-2">
      <p>Remove {membership.user.name} from this project? Their project access will be revoked.</p>
      <div className="flex gap-3">
        <button disabled={disabled} onClick={() => onRemove(membership.id)} className="rounded bg-gray-900 px-3 py-2 text-white disabled:opacity-40">Confirm removal</button>
        <button disabled={disabled} onClick={() => setConfirming(false)} className="underline disabled:opacity-40">Cancel</button>
      </div>
    </div>}
  </li>
}
