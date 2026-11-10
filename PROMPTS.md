# Prompts Used

AI tool: Claude Code (VS Code extension). The prompts below are listed in the order they were given. Wording has been lightly edited for clarity and professionalism; the intent and sequence are unchanged.

---

### 1. Initial request
> I have attached the Securonix engineering assessment, "Malware Analysis Sandbox Booking" (full-stack, concurrency, time handling). Please build the project described in it: a day view with sandboxes as columns and time slots as rows, a booking form, cancellation, REST APIs, seeded sandboxes, and all of the stated business rules (no overlaps, back-to-back allowed, 2-hour maximum, 02:00-03:00 UTC maintenance window, deliberate time-zone handling, and correctness under concurrent bookings, with clear error messages). Build the application only; I will handle the submission steps and the git push myself, so please tell me what I need to submit. Use Python.

*(Assessment text was pasted in full with this prompt.)*

### 2. Change of stack
> Please build this using the Laravel PHP framework for the backend and React for the frontend instead.

### 3. Database
> Please run the project with a real database, either SQL or MongoDB, and then follow the instructions from the assessment above.

### 4. End-to-end verification
> Please run and test everything end to end with the database, and tell me which commands I need to run to check whether it is working.

### 5. Bug report
> Nothing is showing at http://127.0.0.1:8000/. Please investigate and fix it.

### 6. UI improvement
> Please improve the user interface so that it looks more polished and professional.

### 7. Responsive design
> Please check that the UI is responsive on all device sizes (phone, tablet, laptop and desktop) and fix any issues.

### 8. Full verification against the brief
> Please verify that everything (backend, database and UI) works correctly according to the original assessment instructions.

---