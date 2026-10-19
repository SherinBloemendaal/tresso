const PHOTO_TYPES: Record<string, string> = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp', heic: 'image/heic', heif: 'image/heif' }

const PHOTO_EXTENSIONS: Record<string, string[]> = { 'image/jpeg': ['jpg', 'jpeg'], 'image/png': ['png'], 'image/gif': ['gif'], 'image/webp': ['webp'], 'image/heic': ['heic'], 'image/heif': ['heif'] }

/** The photo's name with the extension of what it is now: "IMG_0001.HEIC" turned into a JPEG is sent as "IMG_0001.jpg". */
export function photoName(fileName: string, type: string): string {
  const extensions = PHOTO_EXTENSIONS[type]
  if (extensions === undefined) {
    return fileName
  }
  const dot = fileName.lastIndexOf('.')
  const base = dot > 0 ? fileName.slice(0, dot) : fileName
  const current = dot > 0 ? fileName.slice(dot + 1).toLowerCase() : ''
  return extensions.includes(current) ? fileName : `${base}.${extensions[0]}`
}

/** The type a photo's file extension stands for, if it is a photo's. */
export function photoType(extension: string | undefined): string | undefined {
  return extension === undefined ? undefined : PHOTO_TYPES[extension]
}
