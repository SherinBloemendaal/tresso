/** No message is taller than this on screen; past it, the view scrolls inside. */
export const MAX_BODY_HEIGHT = 20_000

/**
 * The height for a message view after its content reported `measured`. Changes of a pixel are noise. Content sized
 * to the view (vh units) grows by the same step every time the view grows: three equal steps in a row are that loop,
 * not content, so the view stays as it is. `growths` keeps the last two steps between calls.
 */
export function nextBodyHeight(previous: number, measured: number, growths: number[]): number {
  const growth = measured - previous
  if (Math.abs(growth) <= 1) {
    return previous
  }
  const [last, beforeLast] = growths
  growths.splice(0, growths.length, growth, last ?? 0)
  if (growth > 0 && last !== undefined && beforeLast !== undefined && Math.abs(growth - last) <= 2 && Math.abs(last - beforeLast) <= 2) {
    return previous
  }
  return Math.min(measured, MAX_BODY_HEIGHT)
}
