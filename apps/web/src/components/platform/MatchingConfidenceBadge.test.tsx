// @vitest-environment jsdom
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { MatchingConfidenceBadge } from "./MatchingConfidenceBadge";

describe("MatchingConfidenceBadge", () => {
  it("labels product-match confidence without implying parser confidence", () => {
    render(<MatchingConfidenceBadge confidence="B" />);
    expect(screen.getByText("Match confidence B")).not.toBeNull();
    expect(screen.queryByText(/parser/i)).toBeNull();
  });
});
