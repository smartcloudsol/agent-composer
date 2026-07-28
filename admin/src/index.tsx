import { MantineProvider, createTheme } from "@mantine/core";
import { Notifications } from "@mantine/notifications";
import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import "./admin.css";

apiFetch.use(apiFetch.createNonceMiddleware(window.smartcloudComposerAdmin.nonce));

const mount = document.getElementById("smartcloud-composer-admin");
if (!mount) throw new Error(__("Agent Composer admin mount node is missing.", "smartcloud-agent-composer"));

const theme = createTheme({
  respectReducedMotion: true,
  fontFamily: "'Source Sans 3', 'Segoe UI', sans-serif",
  primaryColor: "indigo"
});

createRoot(mount).render(
  <StrictMode>
    <MantineProvider theme={theme} defaultColorScheme="light">
      <Notifications position="top-right" zIndex={100002} />
      <App />
    </MantineProvider>
  </StrictMode>
);
