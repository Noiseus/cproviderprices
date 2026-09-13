import { PageHeader } from "@/components/ui";
import { CreateOrganizationForm } from "@/components/platform/CreateOrganizationForm";

export default function CreatePlatformOrganizationPage() {
  return (
    <div className="platform-stack">
      <PageHeader
        eyebrow="Platform provisioning"
        title="Create organization"
        description="Create the customer account first, then configure its trial and initial owner from the detail page."
      />
      <CreateOrganizationForm />
    </div>
  );
}
