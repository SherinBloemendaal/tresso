/** A calendar color with transparency, for the soft fill of event blocks; the color itself when it is not #RRGGBB. */
export function tint(hex: string, alpha: number): string {
  const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex)
  if (match === null) {
    return hex
  }
  const [, red, green, blue] = match
  return `rgba(${parseInt(red!, 16)}, ${parseInt(green!, 16)}, ${parseInt(blue!, 16)}, ${alpha})`
}
