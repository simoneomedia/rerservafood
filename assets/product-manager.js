(function(){
  if(!window.WCOF_PM) return;
  const apiRoot = WCOF_PM.root;
  const nonce = WCOF_PM.nonce;
  const headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
  const root = document.getElementById('wcof-product-manager');
  if(!root) return;

  function escapeHtml(str){
    return str ? str.replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;'}[c];}) : '';
  }

  function fetchCategories(){
    return fetch(apiRoot + 'products/categories?per_page=100',{headers}).then(r=>r.json());
  }
  function fetchProducts(){
    return fetch(apiRoot + 'products?per_page=100&status=any',{headers}).then(r=>r.json());
  }

  function render(){
    Promise.all([fetchCategories(), fetchProducts()]).then(function(res){
      const cats = res[0];
      const prods = res[1];
      root.innerHTML='';
      const addCat = document.createElement('button');
      addCat.textContent = '+ Category';
      addCat.className = 'btn btn-add';
      addCat.addEventListener('click', addCategory);
      root.appendChild(addCat);
      cats.forEach(function(cat){
        const catDiv = document.createElement('div');
        catDiv.className='wcof-cat';
        const head = document.createElement('div');
        head.className='wcof-cat-header';
        const title = document.createElement('span');
        title.textContent = cat.name;
        head.appendChild(title);
        const delBtn = document.createElement('button');
        delBtn.textContent='Delete';
        delBtn.className='btn btn-del';
        delBtn.addEventListener('click', function(){ deleteCategory(cat.id, cat.count); });
        head.appendChild(delBtn);
        catDiv.appendChild(head);
        const list = document.createElement('div');
        list.className='wcof-prod-list';
        prods.filter(p=>p.categories.some(c=>c.id===cat.id)).forEach(function(p){
          list.appendChild(productCard(p));
        });
        const addProd = document.createElement('button');
        addProd.textContent='+ Product';
        addProd.className='btn btn-add';
        addProd.addEventListener('click', function(){ openForm(null, cat.id); });
        list.appendChild(addProd);
        catDiv.appendChild(list);
        root.appendChild(catDiv);
      });
    });
  }

  function addCategory(){
    const name = prompt('Category name');
    if(!name) return;
    fetch(apiRoot+'products/categories',{method:'POST',headers,body:JSON.stringify({name:name})}).then(render);
  }

  function deleteCategory(id,count){
    if(count>0){ alert('Category not empty'); return; }
    if(!confirm('Delete this category?')) return;
    fetch(apiRoot+'products/categories/'+id+'?force=true',{method:'DELETE',headers}).then(render);
  }

  function productCard(p){
    const div = document.createElement('div');
    div.className='wcof-prod';

    const title = document.createElement('div');
    title.className='wcof-prod-title';
    title.textContent = p.name + ' - ' + p.price;

    const active = document.createElement('div');
    active.className='wcof-active';
    const toggle = document.createElement('label');
    toggle.className='wcof-switch';
    const cb = document.createElement('input');
    cb.type='checkbox';
    cb.checked = p.status === 'publish';
    cb.addEventListener('change', function(){
      const status = cb.checked ? 'publish' : 'draft';
      fetch(apiRoot+'products/'+p.id,{method:'PUT',headers,body:JSON.stringify({status})}).then(render);
    });
    const span = document.createElement('span'); span.className='wcof-slider';
    toggle.appendChild(cb);
    toggle.appendChild(span);
    active.appendChild(toggle);
    const activeTxt = document.createElement('span'); activeTxt.textContent='Active';
    active.appendChild(activeTxt);

    const edit = document.createElement('button');
    edit.textContent='Edit';
    edit.className='btn btn-edit';
    edit.addEventListener('click', function(){ openForm(p); });

    div.appendChild(title);
    div.appendChild(active);
    div.appendChild(edit);
    return div;
  }

  function openForm(product, defaultCat){
    root.innerHTML='';
    const form = document.createElement('form');
    form.className='wcof-prod-form';
    form.innerHTML =
      '<input type="text" name="name" placeholder="Name" required value="'+(product?escapeHtml(product.name):'')+'"/>'+
      '<input type="number" name="price" step="0.01" placeholder="Price" required value="'+(product?product.price:'')+'"/>'+
      '<input type="text" name="image" placeholder="Image URL" value="'+(product&&product.images[0]?product.images[0].src:'')+'"/>'+
      '<textarea name="description" placeholder="Description">'+(product?escapeHtml(product.description.replace(/<[^>]*>/g,'')):'')+'</textarea>'+
      '<input type="text" name="allergens" placeholder="Allergens" value="'+(product?(product.meta_data.find(m=>m.key==='_allergens')||{}).value:'')+'"/>';
    const catSel = document.createElement('select');
    catSel.name='category';
    fetchCategories().then(function(cats){
      cats.forEach(function(c){
        const opt = document.createElement('option');
        opt.value=c.id; opt.textContent=c.name;
        if(product && product.categories.some(x=>x.id===c.id)) opt.selected=true;
        if(!product && defaultCat && defaultCat===c.id) opt.selected=true;
        catSel.appendChild(opt);
      });
    });
    form.appendChild(catSel);
    const save = document.createElement('button'); save.type='submit'; save.textContent='Save';
    form.appendChild(save);
    const cancel = document.createElement('button'); cancel.type='button'; cancel.textContent='Cancel';
    cancel.addEventListener('click', render);
    form.appendChild(cancel);
    form.addEventListener('submit', function(e){
      e.preventDefault();
      const data = new FormData(form);
      const body = {
        name: data.get('name'),
        regular_price: data.get('price'),
        description: data.get('description'),
        categories: [{id: parseInt(data.get('category'),10)}],
        images: data.get('image') ? [{src:data.get('image')}] : [],
        meta_data: [{key:'_allergens', value:data.get('allergens')}] 
      };
      const method = product ? 'PUT' : 'POST';
      const url = apiRoot + 'products' + (product?'/'+product.id:'');
      fetch(url,{method:method,headers:headers,body:JSON.stringify(body)}).then(render);
    });
    root.appendChild(form);
  }

  render();
})();
