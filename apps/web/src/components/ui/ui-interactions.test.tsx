// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { useState } from "react";
import { ThemeToggle } from "../ThemeToggle";
import {
  Button,
  Checkbox,
  ConfirmationDialog,
  Dialog,
  Drawer,
  Select,
  Tabs,
} from ".";

beforeAll(() => {
  Object.defineProperty(window, "matchMedia", {
    writable: true,
    value: vi
      .fn()
      .mockImplementation(() => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      })),
  });
  HTMLDialogElement.prototype.showModal = function () {
    this.setAttribute("open", "");
  };
  HTMLDialogElement.prototype.close = function () {
    this.removeAttribute("open");
    this.dispatchEvent(new Event("close"));
  };
});

afterEach(() => {
  cleanup();
  localStorage.clear();
  document.documentElement.removeAttribute("data-theme");
});

describe("design-system interactions", () => {
  it("supports disabled buttons and labeled native controls", async () => {
    const user = userEvent.setup();
    render(
      <>
        <Button disabled>Save</Button>
        <Checkbox label="Include inactive" />
        <Select label="Location" defaultValue="all">
          <option value="all">All</option>
          <option value="one">One</option>
        </Select>
      </>,
    );
    expect(
      (screen.getByRole("button", { name: "Save" }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    const checkbox = screen.getByRole("checkbox", {
      name: "Include inactive",
    }) as HTMLInputElement;
    await user.click(checkbox);
    expect(checkbox.checked).toBe(true);
    const select = screen.getByRole("combobox", {
      name: "Location",
    }) as HTMLSelectElement;
    await user.selectOptions(select, "one");
    expect(select.value).toBe("one");
  });

  it("moves and activates tabs with arrow keys", async () => {
    const user = userEvent.setup();
    render(
      <Tabs
        label="Record views"
        items={[
          { value: "summary", label: "Summary", panel: "Summary panel" },
          { value: "activity", label: "Activity", panel: "Activity panel" },
        ]}
      />,
    );
    const summary = screen.getByRole("tab", { name: "Summary" });
    summary.focus();
    await user.keyboard("{ArrowRight}");
    const activity = screen.getByRole("tab", { name: "Activity" });
    expect(document.activeElement).toBe(activity);
    expect(activity.getAttribute("aria-selected")).toBe("true");
    expect(screen.getByRole("tabpanel").textContent).toContain(
      "Activity panel",
    );
  });

  it("opens and closes dialogs and drawers while restoring focus", async () => {
    const user = userEvent.setup();
    function Example() {
      const [dialog, setDialog] = useState(false);
      const [drawer, setDrawer] = useState(false);
      return (
        <>
          <Button onClick={() => setDialog(true)}>Open dialog</Button>
          <Button onClick={() => setDrawer(true)}>Open drawer</Button>
          <Dialog
            open={dialog}
            onClose={() => setDialog(false)}
            title="Details"
          >
            Dialog content
          </Dialog>
          <Drawer
            open={drawer}
            onClose={() => setDrawer(false)}
            title="Navigation"
          >
            Drawer content
          </Drawer>
        </>
      );
    }
    render(<Example />);
    const opener = screen.getByRole("button", { name: "Open dialog" });
    await user.click(opener);
    expect(
      screen.getByRole("dialog", { name: "Details" }).hasAttribute("open"),
    ).toBe(true);
    await user.click(screen.getByRole("button", { name: "Close Details" }));
    expect(document.activeElement).toBe(opener);
    await user.click(screen.getByRole("button", { name: "Open drawer" }));
    expect(
      screen.getByRole("dialog", { name: "Navigation" }).hasAttribute("open"),
    ).toBe(true);
  });

  it("uses an explicit confirmation action", async () => {
    const user = userEvent.setup();
    const confirm = vi.fn();
    render(
      <ConfirmationDialog
        open
        onClose={() => undefined}
        onConfirm={confirm}
        title="Remove item"
        confirmLabel="Remove"
      >
        This cannot be undone.
      </ConfirmationDialog>,
    );
    await user.click(screen.getByRole("button", { name: "Remove" }));
    expect(confirm).toHaveBeenCalledOnce();
  });

  it("persists an explicit theme choice and applies it immediately", async () => {
    const user = userEvent.setup();
    render(<ThemeToggle />);
    await user.selectOptions(
      screen.getByRole("combobox", { name: "Theme" }),
      "dark",
    );
    expect(localStorage.getItem("cproviderprices-theme")).toBe("dark");
    expect(document.documentElement.dataset.theme).toBe("dark");
    fireEvent.change(screen.getByRole("combobox", { name: "Theme" }), {
      target: { value: "system" },
    });
    expect(localStorage.getItem("cproviderprices-theme")).toBeNull();
  });
});
