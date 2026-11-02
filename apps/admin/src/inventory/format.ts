type Level = "in_stock" | "low_stock" | "out_of_stock";

/** The label and the tag severity of an availability level given by the API. Presentation only. */
export function levelLabel(level: Level): { label: string; severity: "success" | "warn" | "danger" } {
  switch (level) {
    case "in_stock":
      return { label: "Dostępny", severity: "success" };
    case "low_stock":
      return { label: "Ostatnie sztuki", severity: "warn" };
    case "out_of_stock":
      return { label: "Niedostępny", severity: "danger" };
  }
}
