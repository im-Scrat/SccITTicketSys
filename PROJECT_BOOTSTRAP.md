You are now the Lead Software Architect, Senior Laravel Developer, Senior React Developer, Senior DevOps Engineer, Senior PostgreSQL Database Architect, Senior AI Systems Architect, and Technical Lead for this entire project.

This is NOT a prototype.

This is NOT a hackathon project.

Treat this as a production-quality software project that will eventually be deployed to a real client.

==================================================
PROJECT
==================================================

Project Name:

AI-Powered School IT Asset & Service Management System

This project will be developed for a school.

There are three primary users:

• Teachers
• Technicians
• Administrators

The system will manage:

- IT Service Tickets
- Computer Inventory
- Asset Management
- Preventive Maintenance
- AI Troubleshooting
- AI Knowledge Base
- Technician Assignment
- Repair History
- QR Code Verification
- Analytics
- Reports

The system should be modular, scalable, maintainable, and production-ready.

==================================================
YOUR ROLE
==================================================

You are responsible for setting up EVERYTHING needed before development begins.

Do NOT start implementing business features yet.

Do NOT generate CRUD modules.

Do NOT generate ticket logic.

Do NOT generate AI logic.

Only prepare a professional production-ready development environment.

==================================================
PRIMARY GOAL
==================================================

Bootstrap the ENTIRE development environment so I can immediately begin development.

Everything should be Dockerized.

==================================================
OPERATING SYSTEM
==================================================

Host OS:

Windows 11

Development IDE:

Cursor

AI:

Claude Opus

Docker Desktop is already installed.

Git is installed.

Composer is installed.

Node.js is installed.

PHP is installed.

PostgreSQL and pgAdmin are installed on Windows, but I DO NOT want to use the Windows PostgreSQL instance for this project.

Instead, create a PostgreSQL container inside Docker.

pgAdmin will simply connect to that Docker database.

==================================================
TECH STACK
==================================================

Backend

- Laravel 13
- PHP 8.4+

Frontend

- React 19
- Vite
- TypeScript

Styling

- Tailwind CSS

Database

- PostgreSQL 17

Cache

- Redis

Web Server

- Nginx

Containerization

- Docker
- Docker Compose

Authentication

- Laravel Sanctum

API

- REST API

Development

- Git
- GitHub

AI

The system will later integrate with Gemini API using a service layer and Retrieval-Augmented Generation (RAG).

Do NOT implement AI yet.

==================================================
DATABASE
==================================================

Inside Docker create:

Database:

school_it_service_management

Username:

postgres

Password:

scc26

Port:

5432

Persist data using Docker volumes.

==================================================
DOCKER REQUIREMENTS
==================================================

Create a professional Docker Compose environment.

Include containers for:

- Laravel App
- PHP
- Nginx
- PostgreSQL
- Redis
- Node

Use named volumes where appropriate.

Use a dedicated Docker network.

Configure health checks whenever appropriate.

Follow production-ready Docker practices.

Before creating Docker Compose, inspect my existing Docker environment.

If unrelated containers, images, volumes, or networks already exist from previous projects, do NOT modify or remove them.

Create isolated containers, networks, and volumes specifically for this project.

Use unique, project-specific names to avoid conflicts with any existing Docker resources.

==================================================
PROJECT STRUCTURE
==================================================

Generate a clean professional folder structure.

Separate backend and frontend.

Separate Docker configuration.

Example:

project/

backend/

frontend/

docker/

docs/

scripts/

==================================================
LARAVEL SETUP
==================================================

Install Laravel.

Configure:

- PostgreSQL connection
- Redis
- Sanctum
- Queues
- Scheduler
- Storage
- Logging

Create a clean architecture that supports future modules.

==================================================
FRONTEND SETUP
==================================================

Install React using Vite.

Configure:

TypeScript

Tailwind CSS

Axios

React Router

TanStack Query

Zustand

React Hook Form

Zod

Lucide React

Configure aliases.

Create a scalable folder structure.

==================================================
BACKEND ARCHITECTURE
==================================================

Prepare folders for:

Controllers

Services

Repositories (only if justified)

Policies

Requests

Resources

Jobs

Events

Listeners

Notifications

Traits

Enums

Actions

DTOs (if appropriate)

Keep architecture clean and modular.

==================================================
FRONTEND ARCHITECTURE
==================================================

Prepare folders for:

components

pages

layouts

hooks

contexts

stores

services

types

utils

assets

features

==================================================
CODE QUALITY
==================================================

Configure:

Laravel Pint

ESLint

Prettier

EditorConfig

GitIgnore

Environment examples

==================================================
DOCUMENTATION
==================================================

Generate:

README.md

Installation Guide

Project Structure

Docker Guide

Development Guide

Environment Setup Guide

==================================================
OUTPUT FORMAT
==================================================

Do NOT immediately create files.

Instead follow this workflow.

STEP 1

Inspect my workspace.

STEP 2

Check Docker.

STEP 3

Check PHP.

STEP 4

Check Composer.

STEP 5

Check Node.

STEP 6

Check Git.

STEP 7

Produce a complete implementation plan.

Wait for my approval.

After I approve the plan,

begin implementation automatically one step at a time.

After every completed phase:

- verify everything works
- fix errors
- continue automatically

Never leave the project in a broken state.

==================================================
IMPORTANT RULES
==================================================

Always verify commands before running them.

If a dependency fails, fix it.

If Docker has issues, diagnose them.

If configuration is incorrect, repair it.

If a package is outdated, choose the latest stable version compatible with Laravel 13.

Never assume.

Always verify.

Never duplicate code.

Follow Laravel best practices.

Follow React best practices.

Follow Docker best practices.

Write clean maintainable code.

Comment only where necessary.

==================================================
SUCCESS CRITERIA
==================================================

When finished I should be able to run:

docker compose up -d

and have:

✓ Laravel running

✓ React running

✓ PostgreSQL running

✓ Redis running

✓ Nginx running

All services must communicate correctly.

Laravel must connect successfully to PostgreSQL.

Redis must be connected.

Vite must work.

Hot reload must work.

The project should be ready for database migrations and feature development.


==================================================
FUTURE MODULE (DO NOT IMPLEMENT YET)
==================================================

This project will eventually include an Interactive Floor Plan module.

This module is NOT to be implemented now.

However, the project architecture MUST be designed to fully support it later without major refactoring.

The Interactive Floor Plan module will include:

• Interactive laboratory layouts

• Building management

• Multiple floors

• Laboratories

• Offices

• Drag-and-drop positioning of PC units

• Snap-to-grid positioning

• Zoom and pan

• Save layout positions

• Real-time position updates

• Visual PC icons

• Different icon colors based on status

    - Online

    - Offline

    - Under Maintenance

    - Assigned

    - Available

• Clicking a PC icon opens an information panel.

The information panel will display:

- PC Name
- Laboratory
- Current Status
- Assigned Technician
- Complete Specifications
- Installed Hardware
- QR Code
- Repair History
- Upgrade History
- AI Prediction
- Ticket History
- Maintenance Timeline

The administrator should be able to:

- Drag PC icons
- Drop PC icons
- Move PC icons
- Save layouts
- Edit room layouts
- Add new rooms
- Add buildings
- Add floors

Teachers and technicians will not have this access. Only the admin will have this feature.

The architecture should support future additions such as:

- Network topology visualization

- Heat maps

- Asset density

- AI predicted failures directly on the map

- Indoor navigation

IMPORTANT

Do NOT build this feature.

Only prepare the project architecture so this module can be added later with minimal changes.

Prepare the backend so future modules can be added without restructuring.

The Interactive Floor Plan module will later require services such as:

- BuildingService

- FloorService

- LaboratoryLayoutService

- AssetPositionService

- FloorPlanService

- CoordinateService

Only prepare the architecture.

Do NOT implement them.

Prepare a scalable frontend architecture that can later support:

- Interactive Canvas

- Drag and Drop

- Zoom

- Pan

- Minimap

- Right-click Context Menus

- Information Side Panels

- Asset Layer Rendering

- Grid System

Do NOT implement these features yet.

Only ensure the folder structure and architecture are ready.