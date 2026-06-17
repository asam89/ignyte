/**
 * Lightweight async job queue for the change-request pipeline.
 * In production, replace with QStash, BullMQ, or similar.
 * This implementation uses a simple in-process queue with retry logic.
 */

type JobHandler = (payload: Record<string, unknown>) => Promise<void>;

interface Job {
  id: string;
  type: string;
  payload: Record<string, unknown>;
  attempts: number;
  maxAttempts: number;
  createdAt: Date;
  status: "pending" | "running" | "completed" | "failed";
  error?: string;
}

const handlers = new Map<string, JobHandler>();
const activeJobs = new Map<string, Job>();

export function registerHandler(type: string, handler: JobHandler) {
  handlers.set(type, handler);
}

export async function enqueueJob(
  type: string,
  payload: Record<string, unknown>,
  options?: { maxAttempts?: number }
): Promise<string> {
  const id = `job_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  const job: Job = {
    id,
    type,
    payload,
    attempts: 0,
    maxAttempts: options?.maxAttempts ?? 3,
    createdAt: new Date(),
    status: "pending",
  };

  activeJobs.set(id, job);

  // Process async (fire-and-forget with retry)
  processJob(job).catch(() => {
    // Error handling is done within processJob
  });

  return id;
}

async function processJob(job: Job): Promise<void> {
  const handler = handlers.get(job.type);
  if (!handler) {
    job.status = "failed";
    job.error = `No handler registered for job type: ${job.type}`;
    return;
  }

  while (job.attempts < job.maxAttempts) {
    job.attempts++;
    job.status = "running";

    try {
      await handler(job.payload);
      job.status = "completed";
      return;
    } catch (error) {
      const msg = error instanceof Error ? error.message : "Unknown error";
      job.error = msg;

      if (job.attempts >= job.maxAttempts) {
        job.status = "failed";
        return;
      }

      // Exponential backoff: 1s, 4s, 9s...
      const backoff = job.attempts * job.attempts * 1000;
      await new Promise((resolve) => setTimeout(resolve, backoff));
    }
  }
}

export function getJobStatus(id: string): Job | undefined {
  return activeJobs.get(id);
}
