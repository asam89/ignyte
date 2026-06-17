/**
 * Extract text from uploaded documents for AI context injection.
 * Supports PDF, DOCX, and plain text files.
 */

import mammoth from "mammoth";

export async function extractText(
  buffer: Buffer,
  mimeType: string
): Promise<string> {
  switch (mimeType) {
    case "application/pdf":
      return extractPdfText(buffer);
    case "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
      return extractDocxText(buffer);
    case "text/plain":
    case "text/markdown":
    case "text/html":
      return buffer.toString("utf-8");
    default:
      return "";
  }
}

async function extractPdfText(buffer: Buffer): Promise<string> {
  try {
    const { PDFParse } = await import("pdf-parse");
    const data = new Uint8Array(buffer);
    const parser = new PDFParse(data);
    const result = await parser.getText();
    return result.text.trim();
  } catch {
    return "";
  }
}

async function extractDocxText(buffer: Buffer): Promise<string> {
  try {
    const result = await mammoth.extractRawText({ buffer });
    return result.value.trim();
  } catch {
    return "";
  }
}

/** Max text to store per document (characters) */
export const MAX_EXTRACTED_TEXT_LENGTH = 100_000;

/** Truncate to max length, keeping beginning and end */
export function truncateText(text: string): string {
  if (text.length <= MAX_EXTRACTED_TEXT_LENGTH) return text;
  const half = Math.floor(MAX_EXTRACTED_TEXT_LENGTH / 2);
  return (
    text.slice(0, half) +
    "\n\n[... content truncated ...]\n\n" +
    text.slice(-half)
  );
}
