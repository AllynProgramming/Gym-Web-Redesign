<?php
// landing.php - public GymTrack landing page.
// Logged-in users skip it and go straight to the dashboard.
// If your session key isn't one of these, change it to match includes/auth.php.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
foreach (['user_id', 'userid', 'id', 'user'] as $k) {
    if (!empty($_SESSION[$k])) { header('Location: dashboard.php'); exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>GymTrack | Beat what you lifted last time</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,500..900&family=Newsreader:opsz,wght@6..72,400..600&display=swap" rel="stylesheet">
<style>
:root{--bg:#ECEEEA;--surface:#F7F8F5;--ink:#1D2024;--muted:#5B6168;--rule:#C9CEC9;--accent:#1F4FCC;--on-accent:#fff;
--red:#D3302B;--blue:#1F4FCC;--yellow:#EDBE2B;--green:#1F8A4D;--white:#F4F4F2;--black:#2A2D31;
--head:"Archivo",Arial,sans-serif;--body:"Newsreader",Georgia,serif;
box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#16181B;--surface:#1E2125;--ink:#E8EAE6;--muted:#9AA0A6;--rule:#34383D;--accent:#6C93FF;--on-accent:#0F1216}}
:root[data-theme="dark"]{--bg:#16181B;--surface:#1E2125;--ink:#E8EAE6;--muted:#9AA0A6;--rule:#34383D;--accent:#6C93FF;--on-accent:#0F1216}
html{height:100%;scroll-padding-top:env(safe-area-inset-top,0px)}
*,*::before,*::after{box-sizing:inherit}
body{margin:0;background:var(--bg);color:var(--ink);font:400 1.125rem/1.55 var(--body);-webkit-font-smoothing:antialiased}
h1,h2,h3,.btn,th,.tot,nav a{font-family:var(--head)}
a{color:inherit}
:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.wrap{max-width:1120px;margin:0 auto;padding:0 clamp(1.1rem,4vw,2.5rem)}
header{display:flex;justify-content:space-between;align-items:center;padding:1.4rem 0}
.logo{font:800 1.25rem var(--head);font-stretch:112%;text-decoration:none;letter-spacing:-.01em}
nav{display:flex;gap:1.4rem;align-items:center}
nav a{font-weight:600;font-size:.95rem;text-decoration:none}
.btn{display:inline-block;background:var(--accent);color:var(--on-accent);font-weight:700;font-size:1rem;padding:.8rem 1.4rem;border:0;border-radius:6px;text-decoration:none;cursor:pointer}
.btn.alt{background:transparent;color:var(--ink);box-shadow:inset 0 0 0 2px var(--ink)}
.btn.sm{padding:.55rem 1rem;font-size:.9rem}
h1{font-size:clamp(2.6rem,8vw,5.8rem);font-weight:850;font-stretch:118%;line-height:.98;letter-spacing:-.025em;margin:2.5rem 0 1.2rem;max-width:12ch}
.lede{max-width:34rem;font-size:1.3rem;color:var(--muted);margin:0 0 1.6rem}
.cta{display:flex;gap:.8rem;flex-wrap:wrap}
.demo{display:grid;grid-template-columns:1.25fr 1fr;gap:clamp(1.5rem,4vw,3rem);margin:3.5rem 0 5rem;align-items:start}
.stage{position:relative;display:flex;align-items:center;height:170px;margin:0 0 1rem}
.stage::before{content:"";position:absolute;left:0;right:0;top:50%;height:8px;margin-top:-4px;background:var(--muted);border-radius:4px}
.side{flex:1;display:flex;position:relative;gap:2px;align-items:center}
.side.l{flex-direction:row-reverse}
.shaft{flex:0 0 17%;position:relative;text-align:center;font:600 .8rem var(--head)}
.shaft span{position:absolute;left:0;right:0;top:calc(50% + 14px);color:var(--muted)}
.p{--s:1;width:calc(var(--w)*var(--s)*1px);height:var(--h);border-radius:3px;display:flex;align-items:center;justify-content:center;background:var(--c);color:var(--t,#fff);font:700 .7rem var(--head);writing-mode:vertical-rl;box-shadow:inset 0 0 0 1px rgba(0,0,0,.25)}
.picker{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.1rem}
.pk{border:0;border-radius:50px;padding:.5rem .9rem;font:700 .9rem var(--head);cursor:pointer;background:var(--c);color:var(--t,#fff);box-shadow:inset 0 0 0 1px rgba(0,0,0,.25)}
.pk.ghost{background:transparent;color:var(--ink);box-shadow:inset 0 0 0 1.5px var(--rule)}
.row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.tot{font-size:2.6rem;font-weight:800;font-stretch:112%;font-variant-numeric:tabular-nums;line-height:1}
.tot small{font-size:1rem;font-weight:600;color:var(--muted)}
.step{display:flex;align-items:center;gap:.4rem;font:600 1rem var(--head)}
.step button{width:2.2rem;height:2.2rem;border-radius:6px;border:1.5px solid var(--rule);background:var(--surface);color:var(--ink);font:700 1.1rem var(--head);cursor:pointer}
.step output{min-width:5.5ch;text-align:center;font-variant-numeric:tabular-nums}
label.chk{display:flex;gap:.45rem;align-items:center;font-size:1rem;cursor:pointer}
.log{background:var(--surface);border-radius:10px;padding:1.2rem 1.3rem 1.3rem;box-shadow:0 0 0 1px var(--rule)}
.log h2{font-size:1.25rem;margin:0 0 .6rem;font-weight:750}
table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
th{text-align:left;font-size:.85rem;font-weight:600;color:var(--muted);padding:.4rem 0;border-bottom:2px solid var(--ink)}
td{padding:.5rem 0;border-bottom:1px solid var(--rule)}
td.n,th.n{text-align:right}
.tag{font:600 .75rem var(--head);padding:.15rem .5rem;border-radius:20px;background:var(--yellow);color:#1D2024;margin-left:.4rem}
tr.w td:not(:last-child){color:var(--muted)}
.best{margin:.9rem 0 0;font-size:1rem;color:var(--muted)}
.best b{color:var(--ink);font-family:var(--head)}
section{padding:4rem 0;border-top:1px solid var(--rule)}
h2.big{font-size:clamp(1.9rem,4.5vw,3rem);font-weight:800;font-stretch:112%;line-height:1.05;letter-spacing:-.02em;margin:0 0 2rem;max-width:18ch}
.feat{display:grid;grid-template-columns:1fr 1fr;gap:0 clamp(1.5rem,5vw,4rem)}
.feat div{padding:1.1rem 0;border-top:1px solid var(--rule)}
.feat h3{margin:0 0 .25rem;font-size:1.15rem;font-weight:750}
.feat p{margin:0;color:var(--muted);font-size:1.05rem;max-width:34rem}
.prog{display:grid;grid-template-columns:1fr 1fr;gap:clamp(1.5rem,5vw,4rem);align-items:end}
.chart{display:flex;align-items:flex-end;gap:.5rem;height:240px;border-bottom:2px solid var(--ink);padding-top:1.5rem}
.bar{flex:1;background:var(--blue);border-radius:3px 3px 0 0;position:relative;min-width:0}
.bar.s{background:var(--yellow)}
.bar i{position:absolute;top:-1.4rem;left:0;right:0;text-align:center;font:600 .78rem var(--head);font-style:normal}
.axis{display:flex;gap:.5rem;font:600 .78rem var(--head);color:var(--muted);margin-top:.4rem}
.axis span{flex:1;text-align:center}
.note{color:var(--muted);font-size:1rem;margin-top:.8rem}
.end{display:flex;justify-content:space-between;gap:2rem;align-items:end;flex-wrap:wrap}
footer{padding:2rem 0 3rem;border-top:1px solid var(--rule);color:var(--muted);font-size:.95rem}
@media (max-width:820px){.demo,.feat,.prog{grid-template-columns:1fr}.p{--s:.55}.shaft{flex-basis:14%}nav a.hide{display:none}}
@media (prefers-reduced-motion:no-preference){.p{animation:slide .28s ease-out}@keyframes slide{from{transform:translateX(var(--from,0));opacity:0}}}
</style>
</head>
<body>
<div class="wrap">
<header>
 <a class="logo" href="#">GymTrack</a>
 <nav><a class="hide" href="#features">Features</a><a href="index.php">Log in</a><a class="btn sm" href="index.php">Create account</a></nav>
</header>

<main>
<h1>Beat what you lifted last time.</h1>
<p class="lede">GymTrack logs every set, keeps warm-ups out of your records, and shows whether the bar is really moving.</p>
<div class="cta"><a class="btn" href="index.php">Start logging</a><a class="btn alt" href="#progress">See the progress view</a></div>

<div class="demo" aria-label="Try logging a set">
 <div>
  <div class="stage" id="stage"><div class="side l" id="L"></div><div class="shaft"><span>20 kg bar</span></div><div class="side" id="R"></div></div>
  <div class="picker" id="picker"></div>
  <div class="row">
   <div class="tot" id="tot">20 <small>kg</small></div>
   <div class="step"><button id="rm" aria-label="Fewer reps">-</button><output id="reps" aria-live="polite">5 reps</output><button id="rp" aria-label="More reps">+</button></div>
   <label class="chk"><input type="checkbox" id="wu"> Warm-up set</label>
  </div>
  <p style="margin:1rem 0 0"><button class="btn" id="add">Log set</button></p>
 </div>
 <div class="log">
  <h2>Bench press</h2>
  <table><thead><tr><th>Set</th><th class="n">Weight</th><th class="n">Reps</th><th></th></tr></thead><tbody id="rows"></tbody></table>
  <p class="best" id="best"></p>
 </div>
</div>

<section id="features">
 <h2 class="big">Built around how a session really goes.</h2>
 <div class="feat">
  <div><h3>One row per set</h3><p>Weight, reps and a warm-up tag for each set, so a heavy single is never averaged with your empty-bar sets.</p></div>
  <div><h3>Warm-ups stay out of your records</h3><p>They still show in your history, tagged. Personal records and progression averages only count working sets.</p></div>
  <div><h3>Repeat your last session</h3><p>One button copies your previous workout. Change the weights and start lifting.</p></div>
  <div><h3>Start and end times</h3><p>Duration works itself out. Save when you start and add the end time after you leave the gym.</p></div>
  <div><h3>Nothing lost mid-session</h3><p>Unsaved entries are kept in your browser, so a reload or a dropped connection doesn't cost you a workout.</p></div>
  <div><h3>Jump the weight in one tap</h3><p>After saving, add or remove 2.5 kg, or enter your own number. History is grouped by week. Friend sharing is on the way.</p></div>
 </div>
</section>

<section id="progress">
 <div class="prog">
  <div>
   <h2 class="big">See the week it stalled.</h2>
   <p class="lede" style="margin:0">Each bar is your heaviest working set that week. A flat run shows up at a glance, so you change the plan instead of repeating it.</p>
  </div>
  <div>
   <div class="chart" id="chart" role="img" aria-label="Example: top bench press working set by week, flat at 65 kg for three weeks"></div>
   <div class="axis" id="axis"></div>
   <p class="note">Example data. Weeks 4 to 6 are the same weight, which is the signal to add reps or deload.</p>
  </div>
 </div>
</section>

<section>
 <div class="end">
  <h2 class="big" style="margin:0">Log your next session.</h2>
  <a class="btn" href="index.php">Create account</a>
 </div>
</section>
</main>
<footer>GymTrack, built by a student lifter in Bukidnon. Your logs belong to your account.</footer>
</div>

<script>
const D={25:['var(--red)',150,26],20:['var(--blue)',150,24],15:['var(--yellow)',150,20,'#1D2024'],10:['var(--green)',150,16],5:['var(--white)',118,12,'#1D2024'],2.5:['var(--black)',90,10]};
let load=[],reps=5,rows=[{kg:40,r:8,w:1},{kg:60,r:5,w:0}];
const $=id=>document.getElementById(id);
const plate=k=>{const[c,h,w,t]=D[k];return `<div class="p" style="--c:${c};--h:${h}px;--w:${w};${t?'--t:'+t:''}">${k}</div>`};
const total=()=>20+2*load.reduce((a,b)=>a+b,0);
function draw(){
 const h=load.map(plate).join('');$('L').innerHTML=h;$('R').innerHTML=h;
 $('tot').innerHTML=total()+' <small>kg</small>';$('reps').textContent=reps+(reps===1?' rep':' reps');
 $('rows').innerHTML=rows.map((x,i)=>`<tr class="${x.w?'w':''}"><td>${i+1}${x.w?'<span class="tag">Warm-up</span>':''}</td><td class="n">${x.kg} kg</td><td class="n">${x.r}</td><td></td></tr>`).join('');
 const wk=rows.filter(x=>!x.w).sort((a,b)=>b.kg-a.kg||b.r-a.r)[0];
 $('best').innerHTML=wk?`Best working set: <b>${wk.kg} kg x ${wk.r}</b>`:'No working sets yet. Log one to set a record.';
}
$('picker').innerHTML=Object.keys(D).reverse().map(k=>{const[c,,,t]=D[k];return `<button class="pk" data-k="${k}" style="--c:${c};${t?'--t:'+t:''}" aria-label="Add ${k} kilogram plate to each side">${k}</button>`}).join('')+'<button class="pk ghost" id="undo">Remove plate</button>';
$('picker').onclick=e=>{const b=e.target.closest('button');if(!b)return;
 if(b.id==='undo')load.pop();else if(load.length<5)load.push(+b.dataset.k);
 load.sort((a,b)=>b-a);draw()};
$('rm').onclick=()=>{reps=Math.max(1,reps-1);draw()};
$('rp').onclick=()=>{reps=Math.min(30,reps+1);draw()};
$('add').onclick=()=>{rows.push({kg:total(),r:reps,w:$('wu').checked?1:0});draw()};
draw();
const wkv=[60,62.5,62.5,65,65,65,67.5,70];
$('chart').innerHTML=wkv.map((v,i)=>`<div class="bar${i>=3&&i<=5?' s':''}" style="height:${(v-40)/35*100}%"><i>${v}</i></div>`).join('');
$('axis').innerHTML=wkv.map((_,i)=>`<span>W${i+1}</span>`).join('');
</script>
</body>
</html>