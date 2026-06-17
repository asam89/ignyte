import { S3Client, PutObjectCommand, DeleteObjectCommand, GetObjectCommand } from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";

const s3Client = new S3Client({
  region: "auto",
  endpoint: `https://${process.env.R2_ACCOUNT_ID}.r2.cloudflarestorage.com`,
  credentials: {
    accessKeyId: process.env.R2_ACCESS_KEY_ID || "",
    secretAccessKey: process.env.R2_SECRET_ACCESS_KEY || "",
  },
});

const BUCKET = process.env.R2_BUCKET_NAME || "ignyte-site-manager";
const PUBLIC_URL = process.env.R2_PUBLIC_URL || "";

export interface UploadResult {
  key: string;
  cdnUrl: string;
}

/**
 * Upload a file to R2.
 * Key format: orgId/siteId/type/filename
 */
export async function uploadToR2(
  buffer: Buffer,
  key: string,
  contentType: string
): Promise<UploadResult> {
  await s3Client.send(
    new PutObjectCommand({
      Bucket: BUCKET,
      Key: key,
      Body: buffer,
      ContentType: contentType,
    })
  );

  const cdnUrl = PUBLIC_URL ? `${PUBLIC_URL}/${key}` : key;
  return { key, cdnUrl };
}

/**
 * Delete a file from R2.
 */
export async function deleteFromR2(key: string): Promise<void> {
  await s3Client.send(
    new DeleteObjectCommand({
      Bucket: BUCKET,
      Key: key,
    })
  );
}

/**
 * Get a presigned URL for temporary private access (context docs).
 */
export async function getPresignedUrl(key: string, expiresIn = 3600): Promise<string> {
  const command = new GetObjectCommand({
    Bucket: BUCKET,
    Key: key,
  });

  return getSignedUrl(s3Client, command, { expiresIn });
}

/**
 * Generate a storage key for an asset.
 */
export function assetKey(orgId: string, siteId: string, filename: string): string {
  const sanitized = filename.replace(/[^a-zA-Z0-9._-]/g, "_");
  return `${orgId}/${siteId}/assets/${Date.now()}_${sanitized}`;
}

/**
 * Generate a storage key for a context document.
 */
export function contextDocKey(orgId: string, siteId: string, filename: string): string {
  const sanitized = filename.replace(/[^a-zA-Z0-9._-]/g, "_");
  return `${orgId}/${siteId}/context/${Date.now()}_${sanitized}`;
}
