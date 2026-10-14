import type { Config } from "tailwindcss";

export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        navy: {
          50: "#f3f6fb", 100: "#e6ecf5", 200: "#c2d0e6", 300: "#93acd3",
          400: "#5b7bb3", 500: "#3a5a96", 600: "#2a4479", 700: "#213660",
          800: "#1a2a4a", 900: "#111d34", 950: "#0a1222",
        },
        teal: {
          50: "#effcfb", 100: "#d0f6f3", 200: "#a3edea", 300: "#6cdddb",
          400: "#35c3c5", 500: "#1ba4a8", 600: "#148388", 700: "#14686d",
          800: "#145357", 900: "#144549",
        },
      },
      fontFamily: {
        sans: ["Inter", "system-ui", "sans-serif"],
      },
    },
  },
  plugins: [],
} satisfies Config;