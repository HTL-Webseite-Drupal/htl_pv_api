import express from "express";
import OpenApiValidator from "express-openapi-validator";
import fs from "node:fs";
import path from "node:path";
import yaml from "js-yaml";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = Number(process.env.PORT || 4010);

const OPENAPI_PATH = path.join(__dirname, "openapi.yaml");
const openapiSpec = yaml.load(fs.readFileSync(OPENAPI_PATH, "utf8"));

const app = express();

app.use(express.json());

function randomInt(min, max) {
    const low = Math.ceil(min);
    const high = Math.floor(max);
    return Math.floor(Math.random() * (high - low + 1)) + low;
}

function randomFloat(min, max, decimals = 2) {
    const value = Math.random() * (max - min) + min;
    return Number(value.toFixed(decimals));
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function getDaylightFactor(date) {
    // einfache Tageskurve: 06:00 bis 18:00 aktiv
    const hour = date.getUTCHours() + date.getUTCMinutes() / 60;
    if (hour < 6 || hour > 18) {
        return 0;
    }

    const normalized = (hour - 6) / 12; // 0..1
    return Math.sin(normalized * Math.PI); // 0..1..0
}

function getSeasonFactor(date) {
    // einfache jahreszeitliche Schwankung
    const month = date.getUTCMonth() + 1; // 1..12
    // grob: Winter schlechter, Sommer besser
    const mapping = {
        1: 0.35,
        2: 0.45,
        3: 0.6,
        4: 0.75,
        5: 0.9,
        6: 1.0,
        7: 1.0,
        8: 0.9,
        9: 0.75,
        10: 0.6,
        11: 0.45,
        12: 0.35,
    };

    return mapping[month] ?? 0.7;
}

function getPvPower(date) {
    const daylight = getDaylightFactor(date);
    const season = getSeasonFactor(date);

    if (daylight === 0) {
        return 0;
    }

    const weatherFactor = randomFloat(0.55, 1.0, 3);
    const peakPower = 9000;

    return Math.round(peakPower * daylight * season * weatherFactor);
}

function generateLiveData() {
    const timestamp = new Date();

    const pvPower = getPvPower(timestamp);
    const houseConsumption = randomInt(250, 6500);

    const gridPower = clamp(houseConsumption - pvPower, -5000, 5000);

    return {
        timestamp: timestamp.toISOString(),
        pv_power_w: pvPower,
        grid_power_w: gridPower,
        house_consumption_w: houseConsumption,
    };
}

// Routen
app.get("/pv/live", (req, res) => {
    res.json(generateLiveData());
});

// OpenAPI Validation
app.use(
    OpenApiValidator.middleware({
        apiSpec: OPENAPI_PATH,
        validateRequests: true,
        validateResponses: true,
    }),
);

// Fehlerbehandlung
app.use((err, req, res, next) => {
    const status = err.status || 500;

    res.status(status).json({
        error: {
            message: err.message || "Internal Server Error",
            status,
            details: err.errors || null,
        },
    });
});

app.listen(PORT, "0.0.0.0", () => {
    console.log(`PV mock API listening on http://0.0.0.0:${PORT}`);
});
