import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StatusPill from '../src/components/StatusPill.vue'

describe('StatusPill', () => {
  it('renders a readable label and status colour', () => {
    const w = mount(StatusPill, { props: { status: 'no_show' } })
    expect(w.text()).toBe('No show')
    expect(w.classes()).toContain('text-red-700')
  })
})
