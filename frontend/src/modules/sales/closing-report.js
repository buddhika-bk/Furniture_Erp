const { $, esc, fmt, modal } = UI;

export async function closingReport(session, autoDownload = false) {
  const panel = modal('Daily closing report', `<p>The till is closed. Your saved report includes daily transactions and a payment-method summary.</p>
    <p>Session #${esc(session.id)} · Counted cash: <strong>${fmt.money(session.closing_cash)}</strong> · Variance: <strong>${fmt.money(session.variance)}</strong></p>
    <button class="btn btn-brand" data-download>Download daily closing PDF</button><p data-result role="status" aria-live="polite"></p>`);
  const button=$('[data-download]',panel), result=$('[data-result]',panel);
  const download=async()=>{
    button.disabled=true;result.textContent='Preparing your PDF…';
    try {
      const response=await fetch(`${API.base}/cashier-sessions/${session.id}/report.pdf`,{headers:{Accept:'application/pdf',Authorization:'Bearer '+API.token,...(API.branch?{'X-Branch-Id':API.branch}:{})}});
      if(!response.ok){const error=await response.json().catch(()=>({}));throw new Error(error.message||'Unable to download the closing report.');}
      if(!response.headers.get('content-type')?.includes('application/pdf'))throw new Error('The server did not return a PDF.');
      const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download=`daily-closing-${session.id}.pdf`;a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);
      result.textContent='PDF downloaded. You can download it again from Sales and POS → Daily closing.';
    }catch(error){result.textContent=`The till remains closed. ${error.message} Use the download button to retry.`;}
    finally{button.disabled=false;}
  };
  button.onclick=download;
  if(autoDownload)await download();
}
