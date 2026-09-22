import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { CreateOrganizationForm } from "./CreateOrganizationForm";

const navigation = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => navigation }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  navigation.push.mockReset();
});

describe("CreateOrganizationForm", () => {
  it("prevents duplicate submission while waiting for server confirmation", async () => {
    let resolveMutation: ((value: Response) => void) | undefined;
    const mutation = new Promise<Response>((resolve) => {
      resolveMutation = resolve;
    });
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ message: "ok" }), { status: 200 }),
      )
      .mockReturnValueOnce(mutation);
    vi.stubGlobal("fetch", fetchMock);
    const user = userEvent.setup();
    render(<CreateOrganizationForm />);

    await user.type(
      screen.getByLabelText("Organization name"),
      "Test Restaurant",
    );
    await user.type(
      screen.getByLabelText(/^Organization slug/),
      "test-restaurant",
    );
    await user.click(
      screen.getByRole("button", { name: "Create organization" }),
    );
    const pending = await screen.findByRole("button", { name: "Creating…" });
    expect((pending as HTMLButtonElement).disabled).toBe(true);
    await user.click(pending);
    expect(fetchMock).toHaveBeenCalledTimes(2);

    resolveMutation?.(
      new Response(JSON.stringify({ organization: { id: "0199-test" } }), {
        status: 201,
      }),
    );
    await waitFor(() =>
      expect(navigation.push).toHaveBeenCalledWith(
        "/platform/organizations/0199-test",
      ),
    );
  });

  it("surfaces a structured server error", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ message: "ok" }), { status: 200 }),
      )
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "validation_failed",
              message: "Slug is already used.",
            },
          }),
          { status: 422 },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);
    const user = userEvent.setup();
    render(<CreateOrganizationForm />);
    await user.type(screen.getByLabelText("Organization name"), "Duplicate");
    await user.type(screen.getByLabelText(/^Organization slug/), "duplicate");
    await user.click(
      screen.getByRole("button", { name: "Create organization" }),
    );
    expect(await screen.findByText("Slug is already used.")).toBeTruthy();
  });
});
// @vitest-environment jsdom
