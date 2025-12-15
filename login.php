<?php
// login.php - login form that posts to auth/login.php
session_start();
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

// Check for GET parameter error (e.g., account_inactive)
if (empty($error) && isset($_GET['error'])) {
    if ($_GET['error'] === 'account_inactive') {
        $error = 'Your account has been deactivated. Please contact an administrator.';
    }
}
?>
<!DOCTYPE html>

<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>HCNVMS - Login</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;900&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
<style type="text/tailwindcss">
        .material-symbols-outlined {
            font-variation-settings:
            'FILL' 0,
            'wght' 400,
            'GRAD' 0,
            'opsz' 24
        }
    </style>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "primary": "#4A90E2",
              "background-light": "#F4F7F9",
              "background-dark": "#101922",
              "text-light": "#333333",
              "text-dark": "#E0E0E0",
              "text-muted-light": "#617589",
              "text-muted-dark": "#9EADBB",
              "border-light": "#dbe0e6",
              "border-dark": "#34404c",
              "surface-light": "#ffffff",
              "surface-dark": "#18232e",
              "error": "#D9534F",
            },
            fontFamily: {
              "display": ["Public Sans", "sans-serif"]
            },
            borderRadius: {"DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px"},
          },
        },
      }
    </script>
</head>
<body class="font-display">
<div class="relative flex min-h-screen w-full flex-col items-center justify-center bg-background-light dark:bg-background-dark group/design-root" style='font-family: "Public Sans", "Noto Sans", sans-serif;'>
<div class="flex h-full w-full max-w-6xl flex-1 flex-col overflow-hidden rounded-xl bg-surface-light shadow-xl dark:bg-surface-dark md:flex-row">
<!-- Left Panel -->
<div class="relative hidden w-full flex-col justify-between bg-primary p-8 text-white md:flex md:w-1/2 lg:w-5/12">
<div class="z-10 flex flex-col items-start gap-4">
<div class="flex items-center gap-3">
<div class="flex h-12 w-12 items-center justify-center rounded-lg bg-white/20">
<span class="material-symbols-outlined text-3xl text-white">vaccines</span>
</div>
<h1 class="text-xl font-bold">HCNVMS</h1>
</div>
<div class="flex flex-col gap-2">
<p class="text-2xl font-bold leading-tight">Newborn Vaccination<br/>Monitoring System</p>
<p class="text-sm font-normal text-white/80">Ensuring a healthy start for every child.</p>
</div>
</div>
<div class="relative flex aspect-[1/1] w-full items-center justify-center">
<div class="absolute h-full w-full rounded-full bg-white/10 blur-3xl"></div>
<div class="z-10 h-full w-full bg-cover bg-center bg-no-repeat" data-alt="A soft-focus image of a healthcare worker smiling gently while holding a newborn baby." style='background-image: url("https://lh3.googleusercontent.com/aida-public/AB6AXuBWpDbfPgC41uqjFXvuA8G2pU52KKpF7wM-V3QYQaJEtJz1qujV-7xwu-LDReuI5aTHoG4AZZVyJH19DfUcNBOp2JOWlcUGNrLyYE7KSAG09TN2ZBL8GFPr46aBo9Ioo4ibOWDkITvPF0Xq9mUoV4hwgwSHN5gyyszKrZkP9C0YEvExkzwEt621jcoAE4f1HAa2mMJNmX_d3ga0yLNVMTM6tOvaAYFPmNDOEw7tNwCWrisJk6bvkVc2VKiSnA5XA6UR0i7RARg6Cwc");'></div>
</div>
</div>
<!-- Right Panel -->
<div class="flex w-full flex-col justify-center bg-surface-light px-6 py-12 dark:bg-surface-dark md:w-1/2 md:px-12 lg:w-7/12">
<div class="mx-auto flex w-full max-w-sm flex-col gap-8">
<!-- Page Heading -->
<div class="flex flex-col gap-2 text-center md:text-left">
<p class="text-3xl font-black leading-tight tracking-tight text-text-light dark:text-text-dark">Welcome Back</p>
<p class="text-base font-normal leading-normal text-text-muted-light dark:text-text-muted-dark">Please enter your credentials to log in.</p>
</div>
<!-- Error Display -->
<?php if ($error): ?>
<div class="rounded-md bg-error/10 border border-error p-3 text-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<!-- Form -->
<form id="loginForm" action="auth/login.php" method="post" novalidate class="flex w-full flex-col gap-4">
<!-- Username Field -->
<label class="flex flex-col">
<p class="pb-2 text-sm font-medium leading-normal text-text-light dark:text-text-dark">Username or Email</p>
<div class="flex w-full flex-1 items-stretch rounded-lg">
<span class="flex items-center justify-center rounded-l-lg border border-r-0 border-border-light bg-background-light px-4 text-text-muted-light dark:border-border-dark dark:bg-background-dark dark:text-text-muted-dark">
<span class="material-symbols-outlined text-lg">person</span>
</span>
<input id="username" name="username" class="form-input flex w-full min-w-0 flex-1 resize-none overflow-hidden rounded-r-lg border border-border-light bg-surface-light p-3.5 text-base font-normal leading-normal text-text-light placeholder:text-text-muted-light focus:border-primary focus:outline-0 focus:ring-2 focus:ring-primary/20 dark:border-border-dark dark:bg-surface-dark dark:text-text-dark dark:placeholder:text-text-muted-dark dark:focus:border-primary" placeholder="Enter your username or email" value="" required autocomplete="username"/>
</div>
</label>
<!-- Password Field -->
<label class="flex flex-col">
<p class="pb-2 text-sm font-medium leading-normal text-text-light dark:text-text-dark">Password</p>
<div class="flex w-full flex-1 items-stretch rounded-lg">
<span class="flex items-center justify-center rounded-l-lg border border-r-0 border-border-light bg-background-light px-4 text-text-muted-light dark:border-border-dark dark:bg-background-dark dark:text-text-muted-dark">
<span class="material-symbols-outlined text-lg">lock</span>
</span>
<input id="password" name="password" class="form-input flex w-full min-w-0 flex-1 resize-none overflow-hidden rounded-r-lg border border-border-light bg-surface-light p-3.5 text-base font-normal leading-normal text-text-light placeholder:text-text-muted-light focus:border-primary focus:outline-0 focus:ring-2 focus:ring-primary/20 dark:border-border-dark dark:bg-surface-dark dark:text-text-dark dark:placeholder:text-text-muted-dark dark:focus:border-primary" placeholder="Enter your password" type="password" value="" required autocomplete="current-password"/>
</div>
</label>
<!-- Login Button -->
  <button type="submit" class="flex h-12 w-full items-center justify-center rounded-lg bg-primary px-6 text-base font-bold text-white shadow-sm transition-colors hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 dark:focus:ring-offset-surface-dark">Login</button>
</form>
</div>
<!-- Footer -->
<div class="mt-4 border-t border-border-light pt-6 text-center dark:border-border-dark">
<p class="text-xs text-text-muted-light dark:text-text-muted-dark">© 2024 HCNVMS. All Rights Reserved.</p>
</div>
</div>
</div>
</div>
</div>
</body></html>