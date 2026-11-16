import { describe, expect, it } from 'vitest'
import { label, money, toPaise } from '../src/utils/format'

describe('format', () => {
  it('formats paise with Indian grouping', () => {
    expect(money(12345678)).toBe('₹1,23,456.78')
    expect(money(0)).toBe('₹0.00')
  })
  it('converts rupees to paise without float drift', () => {
    expect(toPaise(19.99)).toBe(1999)
    expect(toPaise('0.1')).toBe(10)
  })
  it('humanises status keys', () => expect(label('in_consultation')).toBe('In consultation'))
})
