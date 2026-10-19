/** No message is taller than this on screen; past it, the view scrolls inside. */
export const MAX_BODY_HEIGHT = 20_000

/** Equal steps in a row that mark content growing with its view rather than content still loading. */
const LOOP_STEPS = 4

/**
 * The height for a message view after its content reported `measured`. Changes of a pixel are noise. Content sized
 * to the view (vh units) grows by the same step every time the view grows; four equal steps in a row are that loop
 * (real content rarely loads in four identical steps), so the view stays as it is. `growths` keeps the last steps
 * between calls.
 */
export function nextBodyHeight(previous: number, measured: number, growths: number[]): number {
  const growth = measured - previous
  if (Math.abs(growth) <= 1) {
    return previous
  }
  growths.unshift(growth)
  growths.splice(LOOP_STEPS)
  if (growth > 0 && growths.length === LOOP_STEPS && growths.every((step) => Math.abs(step - growth) <= 2)) {
    return previous
  }
  return Math.min(measured, MAX_BODY_HEIGHT)
}
