import { NextResponse } from "next/server";

export type WebHealthResponse = {
  status: "ok";
  service: "web";
  timestamp: string;
};

export function createHealthResponse(now: Date = new Date()): WebHealthResponse {
  return {
    status: "ok",
    service: "web",
    timestamp: now.toISOString(),
  };
}

export function GET() {
  return NextResponse.json(createHealthResponse());
}
