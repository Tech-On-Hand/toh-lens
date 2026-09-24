import React from "react";
import ReactDOM from "react-dom/client";
import { getCurrentWindow } from "@tauri-apps/api/window";
import App from "./App";
import { HelpWidget } from "./components/HelpWidget";

// The floating help button is a second window running this same frontend (see help_widget.rs).
const isHelpWidget = getCurrentWindow().label === "help";

ReactDOM.createRoot(document.getElementById("root") as HTMLElement).render(
  <React.StrictMode>
    {isHelpWidget ? <HelpWidget /> : <App />}
  </React.StrictMode>,
);
