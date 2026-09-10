"use client";

import { useState } from "react";
import { ThemeToggle } from "@/components/ThemeToggle";
import {
  Badge,
  Button,
  Card,
  Checkbox,
  ConfidenceBadge,
  ConfirmationDialog,
  DataTableShell,
  Dialog,
  Drawer,
  Dropdown,
  EmptyState,
  ErrorState,
  FilterBar,
  FreshnessBadge,
  IconButton,
  Input,
  PageHeader,
  Pagination,
  PartialDataWarning,
  PermissionState,
  ResponsiveContainer,
  SearchInput,
  Select,
  Skeleton,
  StatCard,
  Tabs,
  Toast,
  Tooltip,
} from "@/components/ui";

export function DesignSystemShowcase() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  return (
    <ResponsiveContainer className="showcase">
      <PageHeader
        eyebrow="Development showcase"
        title="CProviderPrices design system"
        description="Operational primitives and responsive states for implementation review."
        actions={<ThemeToggle />}
      />

      <section className="showcase__section">
        <h2>Controls</h2>
        <div className="showcase__row">
          <Button>Primary action</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="danger">Danger</Button>
          <Button disabled>Disabled</Button>
          <IconButton label="Add item">+</IconButton>
        </div>
        <div className="showcase__grid">
          <Input
            label="Location name"
            placeholder="Downtown"
            hint="Use the operating name."
          />
          <SearchInput placeholder="Search products" />
          <Select label="Location">
            <option>All locations</option>
            <option>Downtown</option>
          </Select>
          <Checkbox
            label="Include inactive items"
            description="Useful for historical review."
          />
        </div>
        <Tabs
          label="Example detail views"
          items={[
            {
              value: "summary",
              label: "Summary",
              panel: <p>Concise operational summary.</p>,
            },
            {
              value: "activity",
              label: "Activity",
              panel: <p>Recent activity will appear here.</p>,
            },
          ]}
        />
      </section>

      <section className="showcase__section">
        <h2>Content and data</h2>
        <div className="showcase__grid showcase__grid--stats">
          <StatCard label="Locations" value="3" detail="1 remaining" />
          <StatCard label="Team members" value="8" detail="Active users" />
          <Card>
            <h3>Badges</h3>
            <div className="showcase__row">
              <Badge tone="accent">Active</Badge>
              <FreshnessBadge state="fresh" />
              <FreshnessBadge state="stale" />
              <ConfidenceBadge confidence="medium" />
            </div>
          </Card>
        </div>
        <DataTableShell
          caption="example records"
          pagination={{ page: 1, totalPages: 4 }}
        >
          <table>
            <caption className="sr-only">Example operational data</caption>
            <thead>
              <tr>
                <th>Item</th>
                <th>Status</th>
                <th>Updated</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Sample item</td>
                <td>
                  <Badge tone="success">Ready</Badge>
                </td>
                <td>Today</td>
              </tr>
            </tbody>
          </table>
        </DataTableShell>
      </section>

      <section className="showcase__section">
        <h2>Feedback and overlays</h2>
        <div className="showcase__row">
          <Button onClick={() => setDialogOpen(true)}>Open dialog</Button>
          <Button variant="secondary" onClick={() => setDrawerOpen(true)}>
            Open drawer
          </Button>
          <Button variant="danger" onClick={() => setConfirmOpen(true)}>
            Confirmation
          </Button>
          <Dropdown label="Actions">
            <Button variant="quiet">Export view</Button>
            <Button variant="quiet">Copy link</Button>
          </Dropdown>
          <Tooltip label="More information">
            <IconButton label="Information">i</IconButton>
          </Tooltip>
        </div>
        <Toast title="Changes saved" tone="success">
          <span>Your preferences are current.</span>
        </Toast>
        <PartialDataWarning>
          Two sources have not reported today.
        </PartialDataWarning>
        <Dialog
          open={dialogOpen}
          onClose={() => setDialogOpen(false)}
          title="Example dialog"
        >
          <p>Focused content uses the native modal dialog behavior.</p>
        </Dialog>
        <Drawer
          open={drawerOpen}
          onClose={() => setDrawerOpen(false)}
          title="Example drawer"
        >
          <p>Navigation and filters can use this responsive surface.</p>
        </Drawer>
        <ConfirmationDialog
          open={confirmOpen}
          onClose={() => setConfirmOpen(false)}
          onConfirm={() => setConfirmOpen(false)}
          title="Confirm action"
        >
          <p>This pattern makes consequential actions explicit.</p>
        </ConfirmationDialog>
      </section>

      <section className="showcase__section">
        <h2>Reusable states</h2>
        <div className="showcase__grid">
          <Skeleton />
          <EmptyState
            title="Nothing here yet"
            description="New records will appear here."
          />
          <ErrorState
            title="Unable to load"
            description="Try again when the connection returns."
          />
          <PermissionState
            title="Permission required"
            description="Ask an organization owner for access."
          />
        </div>
      </section>
      <section className="showcase__section">
        <h2>Filter and pagination shells</h2>
        <FilterBar>
          <SearchInput label="Filter records" />
          <Select label="Status">
            <option>Any status</option>
            <option>Active</option>
          </Select>
        </FilterBar>
        <Pagination page={2} totalPages={6} />
      </section>
      <section className="showcase__widths" aria-label="Target width guidance">
        <div>Desktop · 1100+</div>
        <div>Tablet · 768</div>
        <div>Mobile · 480</div>
      </section>
    </ResponsiveContainer>
  );
}
