import * as DocumentPicker from 'expo-document-picker'
import * as ImagePicker from 'expo-image-picker'
import type { AttachmentFile } from '@tresso/core'

/**
 * What React Native's FormData takes for a file on the device: it reads the file at `uri` when the request is sent,
 * so a large file is never held in JavaScript.
 */
export interface DeviceFile {
  uri: string
  name: string
  type: string
}

/** Files the user picks from the device or a cloud drive; none when they cancel. */
export async function pickDocuments(): Promise<AttachmentFile[]> {
  const result = await DocumentPicker.getDocumentAsync({ multiple: true, copyToCacheDirectory: true })
  if (result.canceled) {
    return []
  }
  return result.assets.map((asset) => {
    const body: DeviceFile = { uri: asset.uri, name: asset.name, type: asset.mimeType ?? 'application/octet-stream' }
    return { name: asset.name, type: body.type, size: asset.size ?? 0, body }
  })
}

/** Photos the user picks; none when they cancel. HEIC comes out as JPEG, which every mail app shows. */
export async function pickPhotos(): Promise<AttachmentFile[]> {
  const result = await ImagePicker.launchImageLibraryAsync({
    mediaTypes: ['images'],
    allowsMultipleSelection: true,
    preferredAssetRepresentationMode: ImagePicker.UIImagePickerPreferredAssetRepresentationMode.Compatible,
    quality: 1,
  })
  if (result.canceled) {
    return []
  }
  return result.assets.map((asset, index) => {
    const type = asset.mimeType ?? 'image/jpeg'
    const name = asset.fileName ?? `Photo ${index + 1}.${type === 'image/png' ? 'png' : 'jpg'}`
    const body: DeviceFile = { uri: asset.uri, name, type }
    return { name, type, size: asset.fileSize ?? 0, body }
  })
}
