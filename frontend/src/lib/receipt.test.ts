import { describe, expect, it } from 'vitest';
import { numbersRow, plainNumber, receiptAmount, receiptCols } from './receipt';

describe('receipt text', () => {
  it('shortens amounts without hiding paise', () => {
    expect(receiptAmount('40.00')).toBe('40.0');
    expect(receiptAmount('12.50')).toBe('12.5');
    expect(receiptAmount('12.55')).toBe('12.55');
    expect(plainNumber('20.00')).toBe('20');
    expect(plainNumber('1.500')).toBe('1.5');
  });

  it('keeps invoice and order on one row at both paper widths', () => {
    for (const width of [58, 80]) {
      const [left, right] = numbersRow('INV-2627-000001', '261102-0001', receiptCols(width));
      expect(left.length + 1 + right.length).toBeLessThanOrEqual(receiptCols(width));
      expect(left).toContain('INV-2627-000001');
    }
  });

  it('drops the order hyphen only when space runs out', () => {
    expect(numbersRow('INV-2627-000001', '261102-0001', 48)).toEqual(['Invoice: INV-2627-000001', 'Order: 261102-0001']);
    expect(numbersRow('INV-2627-000001', '261102-0001', 27)).toEqual(['INV-2627-000001', '#2611020001']);
  });
});
