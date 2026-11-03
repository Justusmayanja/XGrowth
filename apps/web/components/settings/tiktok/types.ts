export type VideoSettings = {
  type: string;
  voice: { style: string; language: string };
  captions: { enabled: boolean; style: string };
  length: string;
  music: { enabled: boolean };
  generate: string;
  brand: {
    use_logo: boolean;
    use_colors: boolean;
    primary: string | null;
    secondary: string | null;
    font: string;
    outro_text: string;
  };
  context: {
    mention_location: boolean;
    location_text: string;
    show_contact: boolean;
    business_name: string;
    website: string;
    phone: string;
  };
};

export const DEFAULT_VIDEO: VideoSettings = {
  type: "motion",
  voice: { style: "female", language: "en" },
  captions: { enabled: true, style: "bold" },
  length: "standard",
  music: { enabled: false },
  generate: "ahead",
  brand: {
    use_logo: false,
    use_colors: false,
    primary: null,
    secondary: null,
    font: "inter",
    outro_text: "",
  },
  context: {
    mention_location: false,
    location_text: "",
    show_contact: false,
    business_name: "",
    website: "",
    phone: "",
  },
};

export function fieldMessage(fields: Record<string, string>, key: string): string | null {
  return fields[key] ?? null;
}

export function completeVideo(value: Partial<VideoSettings> | null | undefined): VideoSettings {
  const video = value ?? {};
  return {
    ...DEFAULT_VIDEO,
    ...video,
    voice: { ...DEFAULT_VIDEO.voice, ...video.voice },
    captions: { ...DEFAULT_VIDEO.captions, ...video.captions },
    music: { ...DEFAULT_VIDEO.music, ...video.music },
    brand: { ...DEFAULT_VIDEO.brand, ...video.brand },
    context: { ...DEFAULT_VIDEO.context, ...video.context },
  };
}
