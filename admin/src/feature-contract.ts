import type { Dispatch, SetStateAction } from "react";
import type {
  ComposerRuntimeStatus,
  ConfigSet
} from "./api";
import type { DocTopic } from "./DocSidebar";

export type Section = "overview" | "configuration" | "blueprints" | "proposals" | "providers" | "audit";

export interface SectionProps {
  section: Section;
  status: ComposerRuntimeStatus;
  sets: ConfigSet[];
  selectedId: string;
  selectedSet: ConfigSet | null;
  chooseSet: (id: string | null) => Promise<void>;
  run: (operation: () => Promise<void>) => Promise<void>;
  refreshSets: (preferred?: string) => Promise<void>;
  setNotice: (notice: string) => void;
  setEntityChangesPending: Dispatch<SetStateAction<boolean>>;
  showDocs: (topic?: DocTopic | null) => void;
}
